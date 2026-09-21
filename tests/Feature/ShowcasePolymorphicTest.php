<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Catalog\Comment;
use Tests\Fixtures\Catalog\Like;
use Tests\Fixtures\Catalog\Product;
use Tests\Fixtures\Catalog\Tag;
use Tests\Fixtures\TestUser;

/**
 * A worked example: "show the user all products tagged 'акция' whose comments have more than 10 likes".
 *
 * Every link of the chain is polymorphic. Likes hang on comments (morphMany), comments hang on
 * products (morphMany), tags reach products through a polymorphic pivot (morphToMany). The test
 * shows what has to be set up and proves that the list and the check of one product agree.
 *
 * The data is built against the two ways such a rule goes wrong. Articles share ids with products
 * and carry their own comments, likes and tags, so a subquery that forgets a morph type counts
 * them in. And product 2 has 13 likes spread over two comments, none above 10, which separates
 * "a comment with more than 10 likes" from "more than 10 likes in total".
 */
class ShowcasePolymorphicTest extends FeatureTestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Who holds permissions.
        Config::set('access.owner_types', [TestUser::class]);

        // 2. Which models conditions may read. Every model on the path is listed, not only the first.
        Config::set('access.resources', [
            'product' => Product::class,
            'comment' => Comment::class,
            'like'    => Like::class,
            'tag'     => Tag::class,
        ]);

        // 3. The morph map of the application. The package needs no setup for it: relations put
        //    the types into subqueries themselves.
        Relation::morphMap(['product' => Product::class, 'comment' => Comment::class, 'article' => 'App\Article']);

        $this->createCatalog();

        // 4. The rule says which model its conditions are about.
        $this->getAccessRules()->newRule('products.view', 'View products', resource: 'product');

        $this->user = TestUser::factory()->make()->forceFill(['id' => 7]);
    }

    public function test_products_tagged_sale_with_a_comment_of_more_than_ten_likes(): void
    {
        $this->user->addPermission('products.view', when: "exists(product.tags, name == 'акция') && exists(product.comments, likes.count > 10)");

        $this->assertVisible([1, 5]);
    }

    /**
     * The other reading of the same sentence: likes of all comments of a product together.
     * A path cannot continue after a to-many relation, so the product gets a relation that
     * reaches likes through comments, and the condition counts it.
     */
    public function test_products_tagged_sale_with_more_than_ten_likes_in_total(): void
    {
        $this->user->addPermission('products.view', when: "exists(product.tags, name == 'акция') && count(product.commentLikes) > 10");

        $this->assertVisible([1, 2, 5]);
    }

    /**
     * A relation that carries a where() of its own. Hidden comments must not count in the list,
     * because they do not count when a loaded product is checked.
     */
    public function test_constraints_of_a_relation_reach_the_list_filter(): void
    {
        DB::table('catalog_comments')->where('id', 11)->update(['hidden' => true]);

        $this->user->addPermission('products.view', when: 'exists(product.visibleComments, likes.count > 10)');

        // Product 1 drops out: its only comment with 12 likes is hidden. Product 3 has no tag,
        // and this condition does not ask for one.
        $this->assertVisible([3, 5]);
    }

    /**
     * The list in one query, and every product asked one by one through Laravel Gate.
     */
    private function assertVisible(array $expected): void
    {
        $this->user->hasPermission('products.view');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $listed  = Product::query()->allowedTo('products.view', $this->user)->orderBy('id')->pluck('id')->all();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $checked = Product::query()->orderBy('id')->get()
            ->filter(fn (Product $product) => $this->user->can('products.view', $product))
            ->pluck('id')->values()->all();

        $this->assertCount(1, $queries, 'the list has to be one query');
        $this->assertSame($expected, $listed, 'list: '.$queries[0]['query']);
        $this->assertSame($expected, $checked, 'check of every product');
    }

    private function createCatalog(): void
    {
        Schema::create('catalog_products', function (Blueprint $t) {
            $t->increments('id');
            $t->string('title');
        });
        Schema::create('catalog_comments', function (Blueprint $t) {
            $t->increments('id');
            $t->string('commentable_type');
            $t->integer('commentable_id');
            $t->boolean('hidden')->default(false);
        });
        Schema::create('catalog_likes', function (Blueprint $t) {
            $t->increments('id');
            $t->string('likeable_type');
            $t->integer('likeable_id');
        });
        Schema::create('catalog_tags', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
        });
        Schema::create('catalog_taggables', function (Blueprint $t) {
            $t->integer('tag_id');
            $t->string('taggable_type');
            $t->integer('taggable_id');
        });

        foreach ([1 => 'акция', 2 => 'новинка'] as $id => $name) {
            Tag::create(['id' => $id, 'name' => $name]);
        }

        //           id  tags    comments: id => likes
        $products = [[1, [1],    [11 => 12]],            // sale, a comment with 12 likes
            [2, [1],    [21 => 10, 22 => 3]],   // sale, 13 likes in total, no comment above 10
            [3, [2],    [31 => 15]],            // enough likes, but no sale tag
            [4, [1],    []],                    // sale, no comments at all
            [5, [1, 2], [51 => 11, 52 => 0]]];  // two tags, one comment with 11 likes

        foreach ($products as [$id, $tags, $comments]) {
            Product::create(['id' => $id, 'title' => 'Product '.$id])->tags()->attach($tags);

            foreach ($comments as $commentId => $likes) {
                Comment::create(['id' => $commentId, 'commentable_type' => 'product', 'commentable_id' => $id]);
                $this->like('comment', $commentId, $likes);
            }
        }

        // Articles with the ids of products 3 and 4. Article 4 has a sale tag and a comment with
        // 50 likes; article 3 has the sale tag that product 3 lacks.
        DB::table('catalog_taggables')->insert([
            ['tag_id' => 1, 'taggable_type' => 'article', 'taggable_id' => 3],
            ['tag_id' => 1, 'taggable_type' => 'article', 'taggable_id' => 4],
        ]);
        Comment::create(['id' => 90, 'commentable_type' => 'article', 'commentable_id' => 4]);
        $this->like('comment', 90, 50);

        // Likes of a product itself, with the id of comment 21: they are not likes of that comment.
        $this->like('product', 21, 5);
    }

    private function like(string $type, int $id, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            Like::create(['likeable_type' => $type, 'likeable_id' => $id]);
        }
    }
}
