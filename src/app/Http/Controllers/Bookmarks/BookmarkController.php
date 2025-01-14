<?php
namespace App\Http\Controllers\Bookmarks;

// karube: グループ化
use App\Http\Controllers\Controller;
use App\Http\Requests\BookmarkCreateRequest;
use App\Http\Requests\BookmarkUpdateRequest;
use App\Models\Bookmark;
use App\Models\BookmarkCategory;
use App\Models\User;
use Artesaos\SEOTools\Facades\SEOTools;
use Dusterio\LinkPreview\Client;
use Dusterio\LinkPreview\Exceptions\UnknownParserException;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Services\Bookmarks\BookmarkService;

class BookmarkController extends Controller
{
    /**
     * ブックマーク一覧画面
     *
     * SEO
     * title, description
     * titleは固定、descriptionは人気のカテゴリTOP5を含める
     *
     * ソート
     * ・投稿順で最新順に表示
     *
     * ページ内に表示される内容
     * ・ブックマーク※ページごとに10件
     * ・最も投稿件数の多いカテゴリ※トップ10件
     * ・最も投稿数の多いユーザー※トップ10件
     *
     * @return Application|Factory|View
     */
    public function list(Request $request)
    {
        // tkrkn: SEOはUI層にControllerから外す？
        /**
         * SEOに必要なtitleタグなどをファサードから設定できるライブラリ
         * @see https://github.com/artesaos/seotools
         */
        SEOTools::setTitle('ブックマーク一覧');

        // mori: 例外：取得クエリエラー
        $bookmarks = Bookmark::query()->with(['category', 'user'])->latest('id')->paginate(10);

        $top_categories = BookmarkCategory::query()->withCount('bookmarks')->orderBy('bookmarks_count', 'desc')->orderBy('id')->take(10)->get();

        // Descriptionの中に人気のカテゴリTOP5を含めるという要件
        SEOTools::setDescription("技術分野に特化したブックマーク一覧です。みんなが投稿した技術分野のブックマークが投稿順に並んでいます。{$top_categories->pluck('display_name')->slice(0, 5)->join('、')}など、気になる分野のブックマークに絞って調べることもできます");

        $top_users = User::query()->withCount('bookmarks')->orderBy('bookmarks_count', 'desc')->take(10)->get();

        return view('page.bookmark_list.index', [
            'h1' => 'ブックマーク一覧',
            'bookmarks' => $bookmarks,
            'top_categories' => $top_categories,
            'top_users' => $top_users
        ]);
    }

    /**
     * カテゴリ別ブックマーク一覧
     *
     * カテゴリが数字で無かった場合404
     * カテゴリが存在しないIDが指定された場合404
     *
     * title, descriptionにはカテゴリ名とカテゴリのブックマーク投稿数を含める
     *
     * 表示する内容は普通の一覧と同様
     * しかし、カテゴリに関しては現在のページのカテゴリを除いて表示する
     *
     * @param Request $request
     * @return Application|Factory|View
     */
    public function listCategory(Request $request)
    {
        $category_id = $request->category_id;
        if (!is_numeric($category_id)) {
            abort(404);
        }

        $category = BookmarkCategory::query()->findOrFail($category_id);

        SEOTools::setTitle("{$category->display_name}のブックマーク一覧");
        SEOTools::setDescription("{$category->display_name}に特化したブックマーク一覧です。みんなが投稿した{$category->display_name}のブックマークが投稿順に並んでいます。全部で{$category->bookmarks->count()}件のブックマークが投稿されています");

        $bookmarks = Bookmark::query()->with(['category', 'user'])->where('category_id', '=', $category_id)->latest('id')->paginate(10);

        // 自身のページのカテゴリを表示しても意味がないのでそれ以外のカテゴリで多い順に表示する
        $top_categories = BookmarkCategory::query()->withCount('bookmarks')->orderBy('bookmarks_count', 'desc')->orderBy('id')->where('id', '<>', $category_id)->take(10)->get();

        $top_users = User::query()->withCount('bookmarks')->orderBy('bookmarks_count', 'desc')->take(10)->get();

        return view('page.bookmark_list.index', [
            'h1' => "{$category->display_name}のブックマーク一覧",
            'bookmarks' => $bookmarks,
            'top_categories' => $top_categories,
            'top_users' => $top_users
        ]);
    }

    /**
     * ブックマーク作成フォームの表示
     * @return Application|Factory|View
     */
    public function showCreateForm()
    {
        if (Auth::id() === null) {
            return redirect('/login');
        }

        SEOTools::setTitle('ブックマーク作成');

        $master_categories = BookmarkCategory::query()->oldest('id')->get();

        return view('page.bookmark_create.index', [
            'master_categories' => $master_categories,
        ]);
    }

    /**
     * ブックマーク作成処理
     *
     * 未ログインの場合、処理を続行するわけにはいかないのでログインページへリダイレクト
     *
     * 投稿内容のURL、コメント、カテゴリーは不正な値が来ないようにバリデーション
     *
     * ブックマークするページのtitle, description, サムネイル画像を専用のライブラリを使って取得し、
     * 一緒にデータベースに保存する※ユーザーに入力してもらうのは手間なので
     * URLが存在しないなどの理由で失敗したらバリデーションエラー扱いにする
     *
     * @param BookmarkCreateRequest $request
     * @param BookmarkService $service
     * @return Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function create(
        BookmarkCreateRequest $request,
        BookmarkService $service,
    ) {
        // --------------------
        // 登録処理
        // --------------------
        $result = $service->create($request->toArray(), Auth::id());

        // 暫定的に成功時は一覧ページへ
        // karube: response共通処理化する？macro化？Resourceクラス使用する？
        return redirect('/bookmarks', Response::HTTP_FOUND);
    }

    /**
     * 編集画面の表示
     * 未ログインであればログインページへ
     * 存在しないブックマークの編集画面なら表示しない
     * 本人のブックマークでなければ403で返す
     *
     * @param Request $request
     * @param int $id
     * @return Application|Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|View
     */
    public function showEditForm(Request $request, int $id)
    {
        if (Auth::guest()) {
            // @note ここの処理はユーザープロフィールでも使われている
            return redirect('/login');
        }

        SEOTools::setTitle('ブックマーク編集');

        $bookmark = Bookmark::query()->findOrFail($id);
        if ($bookmark->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $master_categories = BookmarkCategory::query()->withCount('bookmarks')->orderBy('bookmarks_count', 'desc')->orderBy('id')->take(10)->get();

        return view('page.bookmark_edit.index', [
            'user' => Auth::user(),
            'bookmark' => $bookmark,
            'master_categories' => $master_categories,
        ]);
    }

    /**
     * ブックマーク更新
     * コメントとカテゴリのバリデーションは作成時のそれと合わせる
     * 本人以外は編集できない
     * ブックマーク後24時間経過したものは編集できない仕様
     *
     * @param BookmarkUpdateRequest $request
     * @param int $id
     * @return Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws ValidationException
     */
    public function update(BookmarkUpdateRequest $request, int $id)
    {
        if (Auth::guest()) {
            // @note ここの処理はユーザープロフィールでも使われている
            return redirect('/login');
        }

        // Validator::make($request->all(), [
        //     'comment' => 'required|string|min:10|max:1000',
        //     'category' => 'required|integer|exists:bookmark_categories,id',
        // ])->validate();

        $model = Bookmark::query()->findOrFail($id);

        if ($model->can_not_delete_or_edit) {
            throw ValidationException::withMessages([
                'can_edit' => 'ブックマーク後24時間経過したものは編集できません'
            ]);
        }

        if ($model->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $model->category_id = $request->category;
        $model->comment = $request->comment;
        $model->save();

        // 成功時は一覧ページへ
        return redirect('/bookmarks', Response::HTTP_FOUND);
    }

    /**
     * ブックマーク削除
     * 公開後24時間経過したものは削除できない
     * 本人以外のブックマークは削除できない
     *
     * @param int $id
     * @return Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws ValidationException
     */
    public function delete(int $id)
    {
        if (Auth::guest()) {
            // @note ここの処理はユーザープロフィールでも使われている
            return redirect('/login');
        }

        $model = Bookmark::query()->findOrFail($id);

        if ($model->can_not_delete_or_edit) {
            throw ValidationException::withMessages([
                'can_delete' => 'ブックマーク後24時間経過したものは削除できません'
            ]);
        }

        if ($model->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $model->delete();

        // 暫定的に成功時はプロフィールページへ
        return redirect('/user/profile', Response::HTTP_FOUND);
    }
}
