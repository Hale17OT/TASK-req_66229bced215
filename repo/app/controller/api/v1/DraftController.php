<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\model\DraftRecovery;
use think\Response;

class DraftController extends BaseController
{
    public function show($token): Response
    {
        $row = DraftRecovery::where('user_id', $this->request->userId)->where('draft_token', $token)->find();
        if (!$row) return json_response(40400, 'not found', null, [], 404);
        return json_response(0, 'ok', $row->payload_json);
    }

    public function upsert($token): Response
    {
        $body = $this->request->post();
        $existing = DraftRecovery::where('user_id', $this->request->userId)->where('draft_token', $token)->find();
        $expires = date('Y-m-d H:i:s', time() + 7 * 86400);
        if ($existing) {
            $existing->payload_json = $body;
            $existing->expires_at = $expires;
            $existing->save();
        } else {
            DraftRecovery::create([
                'user_id'      => $this->request->userId,
                'draft_token'  => $token,
                'payload_json' => $body,
                'expires_at'   => $expires,
            ]);
        }
        return json_response(0, 'ok');
    }

    public function destroy($token): Response
    {
        DraftRecovery::where('user_id', $this->request->userId)->where('draft_token', $token)->delete();
        return json_response(0, 'ok');
    }
}
