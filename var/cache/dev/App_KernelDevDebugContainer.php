<?php

// This file has been auto-generated by the Symfony Dependency Injection Component for internal use.

if (\class_exists(\ContainerPhWUhxX\App_KernelDevDebugContainer::class, false)) {
    // no-op
} elseif (!include __DIR__.'/ContainerPhWUhxX/App_KernelDevDebugContainer.php') {
    touch(__DIR__.'/ContainerPhWUhxX.legacy');

    return;
}

if (!\class_exists(App_KernelDevDebugContainer::class, false)) {
    \class_alias(\ContainerPhWUhxX\App_KernelDevDebugContainer::class, App_KernelDevDebugContainer::class, false);
}

return new \ContainerPhWUhxX\App_KernelDevDebugContainer([
    'container.build_hash' => 'PhWUhxX',
    'container.build_id' => 'be250823',
    'container.build_time' => 1697446124,
], __DIR__.\DIRECTORY_SEPARATOR.'ContainerPhWUhxX');
