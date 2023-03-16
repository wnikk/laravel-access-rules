---
title: Basic Usage
weight: 2
---

# Basic Usage

1. In the basic operation, we will use a typical model of rules.

    To do this, it is necessary to configure the expansion to the use of rules and users.
    File `config/access.php`:

    ```php
    /**
     * List of user types.
     * The list can be both the real name of the classes
     * or pseudonyms like "group".
     */
    'owner_types' => [
        App\Models\User::class,
    ],
    ...
    ```

2. Add the necessary trait to your User model:

    ```php
    use Wnikk\LaravelAccessRules\Traits\HasPermissions;
    
    class User extends Model {
        // The User model requires this trait
        use HasPermissions;
    ```

3. Now we are creating basic rules:

    ```php
    use Wnikk\LaravelAccessRules\AccessRules;
    // Add new rule permission
    AccessRules::newRule('articles.edit', 'Access to editing articles');
    ```

4. Fill them with permission:

    ```php
    $user->addPermission('articles.edit');
    ```


5. Now all users who have inherited the rule:

    ```php
    User::find(1)->addPermission('articles.edit');
    ```
    They will be able to access the previously added rules.

    ```php
    $parentUser = User::find(2);
    $user->inheritPermissionFrom($parentUser);
    ```

## How to add a verification of the rules?

1.  You can check through

    ```php
    if ($user->cannot('articles.edit')) abort(403);
    ```

2. You can also use Gate:

    ```php
    Gate::authorize(
        'articles.edit',
        $article
    );
    ```

3. Or use like resource

    ```php
    class ArticleController extends Controller
    {
        public function __construct()
        {
            parent::__construct();
            $this->authorizeResource(Article::class);
        }
        public function index() {...}
        public function create(Request $request)  {...}
        public function store(Request $request)  {...}
        public function show(Article $article) {...}
        public function edit(Article $article) {...}
        public function update(Request $request, Article $article) {...}
        public function destroy(Article $article)  {...}
    }
    ```

## Magic method "self"

If you need to check that the user is the author - for this there is a magic method "self".

An example, we allow the author to edit his comments:

1. To do this, add a rule with a magic suffix "self"

    ```php
    use Wnikk\LaravelAccessRules\AccessRules;
    // Add new rule permission
    AccessRules::newRule('comments.edit'); // for root
    AccessRules::newRule('comments.edit.self'); // for only author
    ```
2. How is the resolution check:

   ```php
    $user = User::find(1);
    $comment = Comment::where('user_id', 1)->first();

    $check = $user->can(
        'comments.edit',
        $comment
    );
    dd($check); // check($user.id === $comment.user_id) result bool:true
    ```
    In this example, the extension checks the presence of the author’s field.

3. The magical method can work with other user models. example:

   ```php
    $moderator = Moderator::find('ffffffff-ffff-4fff-a000-000000000001');
    $comment = Comment::where('moderator_uuid', $moderator->getKey())->first();

    $check = $moderator->can(
        'comments.edit',
        $comment
    );
    dd($check); // check($moderator.uuid === $comment.moderator_uuid) result bool:true
    ```

This magic method will be conveniently used in Laravel policies.
