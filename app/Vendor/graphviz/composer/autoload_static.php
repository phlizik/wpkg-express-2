<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitef3d9d08762af9d56a2041bd87147835
{
    public static $prefixesPsr0 = array (
        'p' => 
        array (
            'phpDocumentor' => 
            array (
                0 => __DIR__ . '/..' . '/phpdocumentor/graphviz/src',
                1 => __DIR__ . '/..' . '/phpdocumentor/graphviz/tests/unit',
            ),
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixesPsr0 = ComposerStaticInitef3d9d08762af9d56a2041bd87147835::$prefixesPsr0;

        }, null, ClassLoader::class);
    }
}
