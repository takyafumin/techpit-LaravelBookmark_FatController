<?php

namespace Services\Bookmarks;

use App\Models\Bookmark;
use Dusterio\LinkPreview\Client;

/**
 * Bookmarks Service
 *
 *
 */
class BookmarkService
{
    /**
     * コンストラクタ
     */
    public function __construct(
        protected Bookmark $bookmark_model
    ) {
    }

    /**
     * ブックマーク作成
     *
     * @param array $bookmark ブックマーク情報
     * @param int   $user_id  投稿者ID
     *
     * @return bool
     */
    public function create(array $bookmark, int $user_id): bool
    {
        // TODO: tkrkn: トランザクション処理
        // TODO: tkrkn: Repository化
        // 下記のサービスでも同様のことが実現できる
        // @see https://www.linkpreview.net/
        // TODO: tkrkn: ClientをFactory化する
        $previewClient = new Client($bookmark['url']);
        $preview = $previewClient->getPreview('general');
        $previewArray = $preview->toArray();

        // TODO: tkrkn: Repository化
        $model = $this->bookmark_model->newInstance();

        $model->url = $bookmark['url'];
        $model->category_id = $bookmark['category'];
        $model->user_id = $user_id;
        $model->comment = $bookmark['comment'];
        $model->page_title = $previewArray['title'];
        $model->page_description = $previewArray['description'];
        $model->page_thumbnail_url = $previewArray['cover'];

        // TODO: リレーションチェック

        // 保存
        // TODO: tkrkn: Repository化
        return $model->save();
    }
}