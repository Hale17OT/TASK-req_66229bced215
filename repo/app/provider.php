<?php
use app\ExceptionHandle;
use app\Request;
use app\service\security\FieldCipher;

return [
    'think\Request'          => Request::class,
    'think\exception\Handle' => ExceptionHandle::class,
    // FieldCipher needs an explicit factory because its constructor reads the
    // ENCRYPTION_KEY env var. Binding it once at boot avoids the auto-wired
    // container trying to call `new FieldCipher()` with no arguments and
    // tripping on a missing key in environments that source secrets late.
    // FieldCipher constructor refuses placeholder/empty keys outside test
    // environments — see app/service/security/FieldCipher.php. The factory
    // simply delegates so the failure surface is one place.
    FieldCipher::class       => fn () => FieldCipher::fromEnv(),
];
