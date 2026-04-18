<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\ExportJob;
use app\service\auth\Authorization;
use app\service\export\ExportService;
use think\Response;

/**
 * Export jobs.
 *
 * Authorization rule (HIGH fix audit-2 #1): every export file is private to
 * the user who created it. Even an audit.view holder cannot read another
 * caller's export — exports are pre-rendered with the requester's data
 * scope, so handing them to another user would leak rows the new viewer is
 * not authorized to see. To inspect another user's data the caller must
 * issue their own export, which gets re-scoped at generation time.
 */
class ExportController extends BaseController
{
    public function index(): Response
    {
        $q = ExportJob::where('requested_by_user_id', $this->request->userId)->order('id', 'desc');
        return json_response(0, 'ok', $q->paginate(['list_rows' => 50, 'page' => max(1, (int)$this->request->get('page', 1))]));
    }

    public function show($id): Response
    {
        $row = ExportJob::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->assertOwnExport($row);
        $arr = $row->toArray(); unset($arr['file_path']);
        return json_response(0, 'ok', $arr);
    }

    public function create(): Response
    {
        app()->make(Authorization::class)->requirePermission((int)$this->request->userId, 'audit.export');
        $kind = (string)$this->request->post('kind', '');
        $filters = (array)$this->request->post('filters', []);
        return json_response(0, 'ok', app()->make(ExportService::class)->enqueue((int)$this->request->userId, $kind, $filters)->toArray());
    }

    public function download($id): Response
    {
        $row = ExportJob::find($id) ?: throw new BusinessException('Not found', 40400, 404);
        $this->assertOwnExport($row);
        if ($row->status !== 'completed') throw new BusinessException('Export not ready: ' . $row->status, 40901, 409);
        if (!is_file((string)$row->file_path)) throw new BusinessException('File missing on disk', 50000, 500);
        return response(file_get_contents((string)$row->file_path), 200)
            ->contentType('text/csv')
            ->header(['Content-Disposition' => 'attachment; filename="' . basename((string)$row->file_path) . '"',
                'X-Sha256' => (string)$row->sha256, 'X-Row-Count' => (string)$row->row_count]);
    }

    /**
     * Per audit-2 finding #1: an export file's contents are bound to the
     * requester's data scope at generation time. Re-serving it to anyone
     * other than the requester would either (a) leak rows outside the new
     * caller's scope, or (b) require regenerating the file — and at that
     * point the caller can simply create their own export. We therefore
     * fail closed.
     */
    private function assertOwnExport(ExportJob $row): void
    {
        if ((int)$row->requested_by_user_id !== (int)$this->request->userId) {
            throw new AuthorizationException(
                'Export jobs are private to the requester. Create your own export instead.'
            );
        }
    }
}
