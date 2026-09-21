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
use Tests\Fixtures\Catalog\RecursiveTag;
use Tests\Fixtures\Catalog\Tag;
use Tests\Fixtures\Catalog\TagsUnder;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;

/**
 * A worked example, continued: products whose tag is any descendant of "акция", with a comment of more than 10 likes.
 *
 * Tags form a tree through parent_id. "Any descendant" is a recursion, and the language of
 * conditions has none, on purpose: every construct of it has to become one SQL fragment and one
 * walk over a loaded record. Functions fill the gap. The test shows the built-in ones first, then what
 * an application can do on its own: a fixed depth, a function of its own, a closure table.
 *
 *     акция (1)
 *       летняя акция (2)
 *         распродажа купальников (4)
 *       чёрная пятница (3)
 *     новинка (5)
 *       новинка недели (6)
 */
class ShowcaseTagTreeTest extends FeatureTestCase
{
    private const LIKED = 'exists(product.comments, likes.count > 10)';

    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class]);
        Config::set('access.resources', ['product' => Product::class, 'comment' => Comment::class, 'like' => Like::class, 'tag' => Tag::class]);
        Relation::morphMap(['product' => Product::class, 'comment' => Comment::class]);

        $this->createCatalog();

        $this->getAccessRules()->newRule('products.view', 'View products', resource: 'product');
        $this->user = TestUser::factory()->make()->forceFill(['id' => 7]);
    }

    /**
     * One level down needs nothing new: "parent" is a relation of a tag to one tag.
     */
    public function test_direct_children_through_the_parent_relation(): void
    {
        $this->user->addPermission('products.view', when: "exists(product.tags, parent.name == 'акция') && ".self::LIKED);

        // Product 3 is tagged with a grandchild, product 1 with "акция" itself.
        $this->assertVisible([2, 6]);
    }

    /**
     * A known depth can be spelled out. It is a stopgap: the condition silently stops matching
     * when somebody adds a fourth level to the tree.
     */
    public function test_fixed_depth_spelled_out(): void
    {
        $this->user->addPermission('products.view', when: "exists(product.tags, parent.name == 'акция' || parent.parent.name == 'акция') && ".self::LIKED);

        $this->assertVisible([2, 3, 6]);
    }

    /**
     * Any depth, through a function of the application. The function does not depend on the record,
     * so the list filter computes it before the query and the database gets "id in (2, 3, 4)".
     */
    public function test_any_depth_through_a_function(): void
    {
        Config::set('access.functions', ['tagsUnder' => TagsUnder::class]);
        $this->app->scoped(TagsUnder::class);

        $this->user->addPermission('products.view', when: "exists(product.tags, id in tagsUnder('акция')) && ".self::LIKED);

        $this->assertVisible([2, 3, 6]);

        $this->assertSame(1, $this->app->make(TagsUnder::class)->walks, 'one walk of the tree for the list and for six separate checks');
    }

    /**
     * Any depth, through a closure table. Nothing of the package is involved in the recursion:
     * "ancestors" is an ordinary many to many relation, and the condition nests one exists() in another.
     */
    public function test_any_depth_through_a_closure_table(): void
    {
        $this->user->addPermission('products.view', when: "exists(product.tags, exists(ancestors, name == 'акция')) && ".self::LIKED);

        $this->assertVisible([2, 3, 6]);
    }

    /**
     * Any depth, with nothing to write: the package walks the tree itself. The tree is found through
     * the relation of the model to itself, "parent" by default.
     */
    public function test_any_depth_through_the_built_in_function(): void
    {
        $this->user->addPermission('products.view', when: "exists(product.tags, id in below('tag.name', 'акция')) && ".self::LIKED);

        $this->assertVisible([2, 3, 6]);
    }

    public function test_the_node_itself_and_everything_below_it(): void
    {
        $this->user->addPermission('products.view', when: "exists(product.tags, id in belowOrSelf('tag.name', 'акция')) && ".self::LIKED);

        $this->assertVisible([1, 2, 3, 6]);
    }

    /**
     * The other direction, and a value that comes from the user: the tag the user works with and all tags above it.
     */
    public function test_everything_above_a_node(): void
    {
        $this->getAccessRules()->newRule('tags.view', 'View tags', resource: 'tag');
        $this->getAccessRules()->newRule('tags.edit', 'Edit tags', resource: 'tag');
        $this->user->forceFill(['tag_id' => 4]);

        $this->user->addPermission('tags.view', when: "tag.id in aboveOrSelf('tag.id', user.tag_id)");
        $this->user->addPermission('tags.edit', when: "tag.id in above('tag.name', 'распродажа купальников', 'parent')");

        $this->assertSame([1, 2, 4], Tag::query()->allowedTo('tags.view', $this->user)->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 2], Tag::query()->allowedTo('tags.edit', $this->user)->orderBy('id')->pluck('id')->all());
        $this->assertTrue($this->user->can('tags.view', Tag::find(4)));
        $this->assertFalse($this->user->can('tags.edit', Tag::find(4)));
        $this->assertFalse($this->user->can('tags.view', Tag::find(3)), 'a sibling branch');
    }

    /**
     * Fifty products checked one by one must not mean fifty walks of the tree.
     */
    public function test_the_tree_is_walked_once_per_request(): void
    {
        $this->user->addPermission('products.view', when: "exists(product.tags, id in below('tag.name', 'акция'))");
        $products = Product::with('tags')->get();
        $this->user->hasPermission('products.view');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $products->each(fn (Product $product) => $this->user->can('products.view', $product));
        Product::query()->allowedTo('products.view', $this->user)->pluck('id');
        $walks = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, 'acr_tree'));
        DB::disableQueryLog();

        $this->assertCount(1, $walks);
    }

    public function test_mistakes_in_a_tree_function_surface_when_the_condition_is_saved(): void
    {
        foreach ([
            "exists(product.tags, id in below('tags.name', 'акция'))"             => 'is not listed in config access.resources',
            "exists(product.tags, id in below('tag.name', 'акция', 'ancestors'))" => 'has to be a belongsTo relation of the model to itself',
            "exists(product.tags, id in below('tag'))"                            => "expects 'alias.column'",
            "exists(product.tags, id in bellow('tag.name', 'акция'))"             => 'Unknown function bellow()',
        ] as $condition => $message) {
            try {
                $this->user->addPermission('products.view', when: $condition);
                $this->fail('Must be refused: '.$condition);
            } catch (InvalidConditionException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * An application that already uses staudenmeir/laravel-adjacency-list needs nothing else:
     * its ancestors() and descendants() are relations, and conditions walk relations.
     *
     * The package is a dev dependency for this one test. It proves that relations which bring
     * their own WITH RECURSIVE into a subquery pass through the SQL compiler untouched.
     */
    public function test_relations_of_laravel_adjacency_list_work_in_conditions(): void
    {
        // MariaDB cannot look from a CTE at the query around it (MDEV-19077), and that package says so about
        // its whereHas('descendants'). Nothing of this package is involved: below() and above() pass there.
        if (DB::getDriverName() === 'mariadb') {
            $this->markTestSkipped('MariaDB does not support correlated CTEs in subqueries, MDEV-19077.');
        }

        Config::set('access.resources', [
            'product' => Product::class, 'comment' => Comment::class, 'like' => Like::class,
            // The relation methods of that package declare no return types, so they are listed by name.
            'tag' => ['model' => RecursiveTag::class, 'relations' => ['ancestors', 'ancestorsAndSelf', 'descendants']],
        ]);

        $this->user->addPermission('products.view', when: "exists(product.recursiveTags, exists(ancestors, name == 'акция')) && ".self::LIKED);
        $this->assertVisible([2, 3, 6]);

        $this->user->remPermission('products.view');
        $this->user->addPermission('products.view', when: "exists(product.recursiveTags, exists(ancestorsAndSelf, name == 'акция')) && ".self::LIKED);
        $this->assertVisible([1, 2, 3, 6]);
    }

    private function assertVisible(array $expected): void
    {
        $this->user->hasPermission('products.view');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $listed  = Product::query()->allowedTo('products.view', $this->user)->orderBy('id')->pluck('id')->all();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $checked = Product::query()->orderBy('id')->get()
            ->filter(fn (Product $product) => $this->user->can('products.view', $product))
            ->pluck('id')->values()->all();

        // A function that walks the tree adds its own queries before the list; the list itself stays one.
        $lists = array_filter($queries, fn (string $sql) => str_contains($sql, 'from "catalog_products"') || str_contains($sql, 'from `catalog_products`'));

        $this->assertCount(1, $lists, 'the list has to be one query');
        $this->assertSame($expected, $listed, 'list: '.end($queries));
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
            $t->integer('parent_id')->nullable();
            $t->string('name');
        });
        Schema::create('catalog_taggables', function (Blueprint $t) {
            $t->integer('tag_id');
            $t->string('taggable_type');
            $t->integer('taggable_id');
        });
        Schema::create('catalog_tag_closure', function (Blueprint $t) {
            $t->integer('tag_id');
            $t->integer('ancestor_id');
        });

        $tags = [1 => [null, 'акция'], 2 => [1, 'летняя акция'], 3 => [1, 'чёрная пятница'], 4 => [2, 'распродажа купальников'], 5 => [null, 'новинка'], 6 => [5, 'новинка недели']];
        foreach ($tags as $id => [$parent, $name]) {
            Tag::create(['id' => $id, 'parent_id' => $parent, 'name' => $name]);
        }
        // What the application would maintain: every pair "tag, ancestor".
        DB::table('catalog_tag_closure')->insert([
            ['tag_id' => 2, 'ancestor_id' => 1], ['tag_id' => 3, 'ancestor_id' => 1],
            ['tag_id' => 4, 'ancestor_id' => 2], ['tag_id' => 4, 'ancestor_id' => 1],
            ['tag_id' => 6, 'ancestor_id' => 5],
        ]);

        //           id  tags    likes of its comments
        $products = [[1, [1],    [12]],       // "акция" itself, not a descendant
            [2, [2],    [11]],       // child
            [3, [4],    [15]],       // grandchild
            [4, [6],    [20]],       // below "новинка"
            [5, [3],    [10, 3]],    // child, but no comment above 10 likes
            [6, [5, 3], [11]]];      // one of two tags is a child

        $commentId = 0;
        foreach ($products as [$id, $tagIds, $likes]) {
            Product::create(['id' => $id, 'title' => 'Product '.$id])->tags()->attach($tagIds);

            foreach ($likes as $count) {
                Comment::create(['id' => ++$commentId, 'commentable_type' => 'product', 'commentable_id' => $id]);
                for ($i = 0; $i < $count; $i++) {
                    Like::create(['likeable_type' => 'comment', 'likeable_id' => $commentId]);
                }
            }
        }
    }
}
