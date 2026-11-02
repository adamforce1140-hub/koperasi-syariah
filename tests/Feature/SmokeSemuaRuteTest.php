<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * SMOKE TEST sementara — meng-visit seluruh rute GET web untuk setiap peran,
 * guna mencari error 500 / exception yang belum tertangkap test yang ada.
 *
 * Rute dengan parameter di-visit memakai ID nyata dari database (dengan
 * beberapa kandidat), sehingga error routing (model tidak ditemukan) dapat
 * dibedakan dari error logika/display yang sesungguhnya.
 *
 * File ini dihapus setelah audit selesai.
 */
class SmokeSemuaRuteTest extends TestCase
{
    use DatabaseTransactions;

    /** Kandidat ID nyata per nama parameter (diisi runtime). */
    private array $kandidat = [];

    public function test_smoke_semua_rute_get(): void
    {
        $this->seedKandidat();

        $roles = ['super_admin', 'pengurus', 'manajer', 'ao', 'teller', 'admin', 'anggota', 'nasabah'];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods())
                && $r->getName()
                && ! str_starts_with($r->uri(), 'api/')
                && ! str_starts_with($r->getName(), 'api.'));

        $tanpaParam = $routes->filter(fn ($r) => count($r->parameterNames()) === 0);
        $denganParam = $routes->filter(fn ($r) => count($r->parameterNames()) > 0);

        fwrite(STDERR, "\nTotal rute GET web: ".$routes->count()
            .' (tanpa param: '.$tanpaParam->count()
            .', dengan param: '.$denganParam->count().")\n");

        foreach ($roles as $role) {
            $u = User::firstOrCreate(
                ['email' => "smoke.{$role}@test.local"],
                ['name' => "Smoke {$role}", 'password' => Hash::make('x'), 'role' => $role, 'is_active' => true]
            );

            $errs = [];

            foreach ($tanpaParam as $route) {
                $this->visit($u, $route, [], $errs);
            }

            foreach ($denganParam as $route) {
                $this->visitDenganParam($u, $route, $errs);
            }

            fwrite(STDERR, "=== {$role} : ".count($errs)." temuan ===\n");
            foreach (array_unique($errs) as $e) {
                fwrite(STDERR, "  {$e}\n");
            }
        }

        $this->assertTrue(true);
    }

    /** Kunjungi rute tanpa parameter. */
    private function visit(User $u, $route, array $extra, array &$errs): void
    {
        $this->resetGuard();

        try {
            $this->withoutExceptionHandling();
            $response = $this->actingAs($u)->get('/'.$route->uri(), $extra);
            $code = $response->getStatusCode();
            $this->withExceptionHandling();

            if ($code >= 500) {
                $errs[] = "{$code} {$route->getName()} ({$route->uri()})";
            }
        } catch (\Throwable $e) {
            $this->withExceptionHandling();
            $this->catat($e, $route->getName(), $route->uri(), $errs);
        }
    }

    /** Kunjungi rute berparameter dengan beberapa kandidat ID nyata. */
    private function visitDenganParam(User $u, $route, array &$errs): void
    {
        $params = $route->parameterNames();
        $variasi = $this->variasiParam($params, $route);

        $temuValid = false;

        foreach ($variasi as $i => $subs) {
            $uri = $this->isiUri($route->uri(), $params, $subs);
            if ($uri === null) {
                continue;
            }

            $this->resetGuard();

            try {
                $this->withoutExceptionHandling();
                $response = $this->actingAs($u)->get('/'.$uri);
                $code = $response->getStatusCode();
                $this->withExceptionHandling();

                $temuValid = true;

                if ($code >= 500) {
                    $errs[] = "{$code} {$route->getName()} ({$uri})";
                }
            } catch (ModelNotFoundException $e) {
                $this->withExceptionHandling();

                continue; // ID salah / data tidak ada — abaikan
            } catch (NotFoundHttpException $e) {
                $this->withExceptionHandling();

                continue;
            } catch (\Throwable $e) {
                $this->withExceptionHandling();
                $this->catat($e, $route->getName(), $uri, $errs);

                return;
            }
        }

        if (! $temuValid) {
            // Semua kandidat ID tidak ketemu di database; tidak bisa diuji.
            $errs[] = "SKIP {$route->getName()} ({$route->uri()}) :: tidak ada ID valid untuk uji";
        }
    }

    private function catat(\Throwable $e, string $name, string $uri, array &$errs): void
    {
        if ($e instanceof AuthorizationException) {
            return; // 403 karena izin — bukan error
        }

        // HttpException dengan status < 500 = respons HTTP yang disengaja
        // (403 akses ditolak, 404 tidak ditemukan, 401 belum login).
        if ($e instanceof HttpException
            && $e->getStatusCode() < 500) {
            return;
        }

        $file = $e->getFile();
        $line = $e->getLine();
        $ringkas = str_replace(base_path().'\\', '', $file);

        $errs[] = "EXC {$name} ({$uri}) :: ".class_basename($e).' :: '
            .substr($e->getMessage(), 0, 220)." @ {$ringkas}:{$line}";
    }

    private function resetGuard(): void
    {
        config(['auth.defaults.guard' => 'web']);
        app('auth')->forgetGuards();
    }

    private function isiUri(string $uri, array $params, array $subs): ?string
    {
        foreach ($params as $p) {
            if (! isset($subs[$p])) {
                return null;
            }
            $uri = str_replace('{'.$p.'}', (string) $subs[$p], $uri);
            $uri = str_replace('{'.$p.'?}', (string) $subs[$p], $uri);
        }

        return str_contains($uri, '{') ? null : $uri;
    }

    /**
     * Bangun variasi substitusi parameter dari ID nyata di database.
     */
    private function variasiParam(array $params, $route = null): array
    {
        $variasi = [[]];

        foreach ($params as $p) {
            $opsi = $this->kandidatModel($route, $p);
            if ($opsi === []) {
                $opsi = $this->kandidat[$p] ?? [];
            }
            if ($opsi === []) {
                $opsi = [1];
            }

            $baru = [];
            foreach ($variasi as $v) {
                foreach ($opsi as $o) {
                    $baru[] = $v + [$p => $o];
                }
            }
            $variasi = array_slice($baru, 0, 6);
        }

        return $variasi;
    }

    /**
     * Ambil ID nyata dari model Eloquent yang di-type-hint pada parameter
     * controller, sehingga rute ber-model-binding ter-visit dengan data valid.
     */
    private function kandidatModel($route, string $param): array
    {
        if (! $route) {
            return [];
        }

        $uses = $route->getAction('uses');
        if (! is_string($uses) || ! str_contains($uses, '@')) {
            return [];
        }

        [$class, $method] = explode('@', $uses);

        if (! class_exists($class)) {
            return [];
        }

        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($ref->getParameters() as $rp) {
            if ($rp->getName() !== $param) {
                continue;
            }

            $type = $rp->getType();
            if (! $type instanceof \ReflectionNamedType) {
                continue;
            }

            $modelClass = $type->getName();
            if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            try {
                $ids = $modelClass::query()->limit(2)->pluck('id')
                    ->map(fn ($v) => (int) $v)->all();
            } catch (\Throwable $e) {
                $ids = [];
            }

            if ($ids) {
                return $ids;
            }
        }

        return [];
    }

    private function seedKandidat(): void
    {
        $peta = [
            'anggota' => 'anggota',
            'anggota_id' => 'anggota',
            'nasabah' => 'anggota',
            'nasabah_id' => 'anggota',
            'rekening' => 'rekening',
            'rekening_id' => 'rekening',
            'deposito' => 'depositos',
            'deposito_id' => 'depositos',
            'pembiayaan' => 'pembiayaan',
            'pembiayaan_id' => 'pembiayaan',
            'angsuran' => 'angsuran',
            'angsuran_id' => 'angsuran',
            'produk' => 'produk_pembiayaan',
            'produk_id' => 'produk_pembiayaan',
            'akun' => 'coas',
            'akun_id' => 'coas',
            'user' => 'users',
            'user_id' => 'users',
            'transaksi' => 'transaksi',
            'transaksi_id' => 'transaksi',
            'jurnal' => 'jurnal',
            'jurnal_id' => 'jurnal',
        ];

        foreach ($peta as $param => $tabel) {
            $ids = [];
            try {
                if (Schema::hasTable($tabel)) {
                    $ids = DB::table($tabel)->limit(2)->pluck('id')
                        ->map(fn ($v) => (int) $v)->all();
                }
            } catch (\Throwable $e) {
                $ids = [];
            }

            $this->kandidat[$param] = $ids ?: [1];
        }

        // Parameter generik 'id' — coba beberapa tabel relevan.
        $umum = [];
        foreach (['pembiayaan', 'pengajuan_pembiayaan', 'pengajuan_rekening', 'restrukturisasi', 'transaksi', 'produk_pembiayaan'] as $t) {
            try {
                if (Schema::hasTable($t)) {
                    foreach (DB::table($t)->limit(1)->pluck('id') as $v) {
                        $umum[] = (int) $v;
                    }
                }
            } catch (\Throwable $e) {
                // abaikan
            }
        }
        $this->kandidat['id'] = $umum ?: [1];
    }
}
