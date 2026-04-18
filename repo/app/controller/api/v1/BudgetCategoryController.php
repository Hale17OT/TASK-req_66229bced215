<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\BudgetCategory;
use app\service\audit\AuditService;
use app\service\auth\PermissionResolver;
use think\Response;

class BudgetCategoryController extends BaseController
{
    private function requirePerm(string $p): void
    {
        if (!app()->make(PermissionResolver::class)->has((int)$this->request->userId, $p)) throw new AuthorizationException();
    }

    public function index(): Response
    {
        if (!app()->make(PermissionResolver::class)->hasAny((int)$this->request->userId, ['budget.view', 'budget.manage_categories'])) {
            throw new AuthorizationException();
        }
        return json_response(0, 'ok', BudgetCategory::order('name')->select());
    }

    public function create(): Response
    {
        $this->requirePerm('budget.manage_categories');
        $data = $this->request->only(['name', 'code', 'description'], 'post');
        $name = trim((string)($data['name'] ?? ''));
        if (mb_strlen($name) < 1 || mb_strlen($name) > 128) throw new BusinessException('Name 1-128 chars', 40000, 422);
        if (BudgetCategory::where('name', $name)->find()) throw new BusinessException('Category name already exists', 40000, 422, ['name' => ['duplicate']]);
        $row = BudgetCategory::create([
            'name' => $name, 'code' => $data['code'] ?? null, 'description' => $data['description'] ?? null,
            'created_by' => (int)$this->request->userId,
        ]);
        app()->make(AuditService::class)->record('budget_category.created', 'budget_category', $row->id, null, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }

    public function update($id): Response
    {
        $this->requirePerm('budget.manage_categories');
        $row = BudgetCategory::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $before = $row->toArray();
        $data = $this->request->only(['name', 'code', 'description', 'status'], 'put');
        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            $clash = BudgetCategory::where('name', $name)->where('id', '<>', $row->id)->find();
            if ($clash) throw new BusinessException('Name already used', 40000, 422);
            $row->name = $name;
        }
        if (array_key_exists('code', $data)) $row->code = $data['code'];
        if (array_key_exists('description', $data)) $row->description = $data['description'];
        if (isset($data['status']) && in_array($data['status'], ['active','archived'], true)) $row->status = $data['status'];
        $row->save();
        app()->make(AuditService::class)->record('budget_category.updated', 'budget_category', $row->id, $before, $row->toArray());
        return json_response(0, 'ok', $row->toArray());
    }
}
