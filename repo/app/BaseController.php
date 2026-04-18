<?php
namespace app;

use think\App;
use think\exception\ValidateException;
use think\Validate;

abstract class BaseController
{
    protected App $app;
    protected Request $request;

    /** Whether to throw on validation failure (true) or return errors array (false). */
    protected bool $failException = true;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->request;
        $this->initialize();
    }

    protected function initialize(): void
    {
    }

    protected function validate(array $data, $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : "\\app\\validate\\{$validate}";
            /** @var Validate $v */
            $v = new $class();
            if (!empty($scene)) $v->scene($scene);
        }
        $v->message($message);
        if ($batch) $v->batch(true);
        return $v->failException($this->failException)->check($data);
    }
}
