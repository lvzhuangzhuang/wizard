<?php
/**
 * wizard
 *
 * @link      https://www.yunsom.com/
 * @copyright 管宜尧 <guanyiyao@yunsom.com>
 */

namespace App\Http\Controllers;


use App\Repositories\Document;
use App\Repositories\DocumentHistory;
use App\Repositories\Project;
use Illuminate\Http\Request;

class DocumentController extends Controller
{

    /**
     * 创建一个新文档页面
     *
     * @param Request $request
     * @param         $id
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function newPage(Request $request, $id)
    {
        $this->validate(
            $request,
            ['type' => 'in:swagger,doc']
        );

        /** @var Project $project */
        $project = Project::where('id', $id)->firstOrFail();

        $this->authorize('page-add', $project);

        $type = $request->input('type', 'doc');
        return view("doc.{$type}", [
            'newPage'   => true,
            'project'   => $project,
            'navigator' => navigator($project->pages, (int)$id),
        ]);
    }

    /**
     * 编辑文档页面
     *
     * @param $id
     * @param $page_id
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function editPage($id, $page_id)
    {
        /** @var Document $pageItem */
        $pageItem = Document::where('project_id', $id)->where('id', $page_id)->firstOrFail();

        $this->authorize('page-edit', $pageItem);

        $viewName = 'doc.' . ((int)$pageItem->type === Document::TYPE_DOC ? 'doc' : 'swagger');
        return view($viewName, [
            'pageItem'  => $pageItem,
            'project'   => $pageItem->project,
            'newPage'   => false,
            'navigator' => navigator(Document::where('project_id', $id)->get(), (int)$id,
                (int)$pageItem->pid, [$pageItem->id]),
        ]);
    }


    /**
     * 创建一个新文档
     *
     * @param Request $request
     * @param         $id
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function newPageHandle(Request $request, $id)
    {
        $this->validate(
            $request,
            [
                'project_id' => "required|integer|min:1|in:{$id}|project_exist",
                'title'      => 'required|between:1,255',
                'type'       => 'required|in:doc,swagger',
                'pid'        => 'integer|min:0',
            ],
            [
                'title.required' => '页面标题不能为空',
                'title.between'  => '页面标题格式不合法',
            ]
        );

        $this->authorize('page-add', $id);

        $pid       = $request->input('pid', 0);
        $projectID = $request->input('project_id');
        $title     = $request->input('title');
        $content   = $request->input('content');
        $type      = $request->input('type', 'doc');

        $pageItem = Document::create([
            'pid'               => $pid,
            'title'             => $title,
            'description'       => '',
            'content'           => $content,
            'project_id'        => $projectID,
            'user_id'           => \Auth::user()->id,
            'last_modified_uid' => \Auth::user()->id,
            'type'              => $type == 'doc' ? Document::TYPE_DOC : Document::TYPE_SWAGGER,
            'status'            => 1,
        ]);

        // 记录文档变更历史
        DocumentHistory::write($pageItem);

        $request->session()->flash('alert.message', '文档创建成功');
        return redirect(wzRoute(
            'project:doc:edit:show',
            ['id' => $projectID, 'page_id' => $pageItem->id]
        ));

    }

    /**
     * 更新文档内容
     *
     * @param Request $request
     * @param         $id
     * @param         $page_id
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function editPageHandle(Request $request, $id, $page_id)
    {
        $this->validate(
            $request,
            [
                'project_id' => "required|integer|min:1|in:{$id}|project_exist",
                'page_id'    => "required|integer|min:1|in:{$page_id}|page_exist:{$id}",
                'pid'        => "required|integer|min:0|page_exist:{$id},false",
                'title'      => 'required|between:1,255',
            ]
        );

        $pid       = $request->input('pid', 0);
        $projectID = $request->input('project_id');
        $title     = $request->input('title');
        $content   = $request->input('content');

        /** @var Document $pageItem */
        $pageItem = Document::where('id', $page_id)->firstOrFail();

        $this->authorize('page-edit', $pageItem);

        $pageItem->pid               = $pid;
        $pageItem->project_id        = $projectID;
        $pageItem->title             = $title;
        $pageItem->content           = $content;
        $pageItem->last_modified_uid = \Auth::user()->id;

        $pageItem->save();

        // 记录文档变更历史
        DocumentHistory::write($pageItem);

        $request->session()->flash('alert.message', '文档信息已更新');
        return redirect(wzRoute(
            'project:doc:edit:show',
            ['id' => $projectID, 'page_id' => $page_id]
        ));
    }

    /**
     * 删除文档
     *
     * @param Request $request
     * @param         $id
     * @param         $page_id
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function deletePage(Request $request, $id, $page_id)
    {
        $pageItem = Document::where('id', $page_id)->where('project_id', $id)->firstOrFail();
        $this->authorize('page-edit', $pageItem);

        // 页面删除后，所有下级页面全部移动到该页面的上级
        $pageItem->subPages()->update(['pid' => $pageItem->pid]);

        // 更新删除文档的用户
        $pageItem->last_modified_uid = \Auth::user()->id;
        $pageItem->save();

        // 删除文档
        $pageItem->delete();

        $request->session()->flash('alert.message', '文档删除成功');
        return redirect(wzRoute('project:home', ['id' => $id]));
    }
}