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
    $user->newRule('articles.edit', 'Access to editing articles');
    ```
    or
    ```php
    use Wnikk\LaravelAccessRules\AccessRules;
    // Add new rule permission
    app(AccessRules::class)->newRule('articles.edit', 'Access to editing articles');
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
