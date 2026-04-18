<?php
namespace app;

class Request extends \think\Request
{
    /** Currently authenticated user id (set by AuthRequired middleware). */
    public ?int $userId = null;

    /** Currently authenticated user model (lazy). */
    public ?\app\model\User $user = null;

    /** Effective scope assignments resolved at auth time. */
    public array $scope = [];

    public function actingAs(\app\model\User $user, array $scope = []): void
    {
        $this->user = $user;
        $this->userId = (int)$user->id;
        $this->scope = $scope;
    }
}
