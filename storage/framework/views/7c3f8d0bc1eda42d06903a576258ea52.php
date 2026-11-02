<?php if (isset($component)) { $__componentOriginal38422736012f5293606d606328e57aed = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal38422736012f5293606d606328e57aed = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.pesan-galat','data' => ['errors' => $errors]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('pesan-galat'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['errors' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($errors)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal38422736012f5293606d606328e57aed)): ?>
<?php $attributes = $__attributesOriginal38422736012f5293606d606328e57aed; ?>
<?php unset($__attributesOriginal38422736012f5293606d606328e57aed); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal38422736012f5293606d606328e57aed)): ?>
<?php $component = $__componentOriginal38422736012f5293606d606328e57aed; ?>
<?php unset($__componentOriginal38422736012f5293606d606328e57aed); ?>
<?php endif; ?><?php /**PATH C:\laragon\www\koperasi_syariah\storage\framework\views/9e7f3f827b9b02b2b684495f76ff685e.blade.php ENDPATH**/ ?>