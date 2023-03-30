---
title: Tutorial step-by-step
weight: 3
---

![ACR, ACL, RBAC - Access Control Rules in Laravel 10: Best Practices and Code Examples. Protect your application with advanced access control. Follow our step-by-step guide to implement GRUD functionality, access rules, and user roles. Don't let hackers get access to your sensitive data](https://dev-to-uploads.s3.amazonaws.com/uploads/articles/jzr3m6s65dgs5hzo6shj.png)

Originally posted on: [https://dev.to/wnikk/how-use-access-control-rules-and-grud-in-laravel-10-tutorial-step-by-step-307a](https://dev.to/wnikk/how-use-access-control-rules-and-grud-in-laravel-10-tutorial-step-by-step-307a)

Download the sample code: [https://github.com/wnikk/-laravel-access-example](https://github.com/wnikk/-laravel-access-example)

In this article, we'll dive into how to implement _Role-Based Access Control_ (RBAC) in Laravel 10, to manage user access effectively.
**RBAC** is a security model where users are assigned roles based on their job responsibilities and access rights are granted to these roles.
This methodology ensures that only authorized users have access to specific functionalities and data within the application.
To implement RBAC, we'll be using a package "[**wnikk/laravel-access-rules**](https://github.com/wnikk/laravel-access-rules)" from Github that simplifies the creation of roles and permissions.
We'll cover the steps involved in creating roles and permissions, assigning them to users, and protecting sensitive information from unauthorized access.

One of the primary advantages of implementing RBAC in Laravel is that it enables granular access control.
With RBAC, you can define roles for different job positions, which restrict access to features and data within the application.
For example, you can create an "_admin_" role with full access to the application, while a "_guest_" role might only be able to view certain pages.
You can also create custom roles that have access to specific functionalities, such as "_content manager_" or "_billing specialist._"
This way, users only have access to the functionalities they need to perform their job duties.

![This user profile page](https://dev-to-uploads.s3.amazonaws.com/uploads/articles/knfpi0tt044k7r4a9n6f.png)

To create RBAC in Laravel, we'll be using a composer "**wnikk/laravel-access-rules**" package that provides a simple and flexible way to create roles and permissions.
This package allows us to assign roles to users, assign permissions to roles, and assign permissions directly to users.
We'll cover the steps involved in setting up the package, defining roles and permissions, and assigning them to users.
By following this step-by-step guide, you can easily implement RBAC in your Laravel application and ensure the security of your user accounts.

## Where will we start?

To simplify the process of implementing Laravel permission:
1. **User Management** - We create user management using Laravel 10.
   This allows for easier application of Laravel permission.
2. **Rules Management** - Additionally, we implement rules management to limit access to content by defining a list of rules for the project.
3. **Permits and inheritance Management** - Permissions management can be used to add roles to user accounts and assign Laravel permission to them.
4. **News Management** - Finally, we can implement news management and apply Laravel permission with each role assigned to a user.

We'll also be utilizing **CRUD** functionality, which refers implement a persistent application: _create, read, update, and delete_.
We'll apply CRUD to all the models in our project to allow for easy management of data.

If you're looking for examples of the concepts discussed in this article, you can find them readily available in the corresponding **GitHub repository**. Simply [navigate to the repository and browse through the source code](https://github.com/wnikk/-laravel-access-example) to see how the various implementations have been carried out. This will provide you with a better understanding of how the concepts work in practice and how you can apply them to your own projects.

## Step 1: Create Laravel application
To begin implementing Laravel 10, the first step is to _create a new Laravel application_. To do this, open up your terminal or command prompt and initiate the creation of a new Laravel application. By following this step, you'll be on your way to implementing Laravel 10 and all of its features in your web application:
```bash
composer create-project laravel/laravel rules-example
```


## Step 2: Install Packages
Next, we'll need to install the required _Wnikk_ package for **Access Control Rules** (ACR) and a visual control package. This can easily be done by opening up your terminal and executing the commands provided below.
```bash
composer require wnikk/laravel-access-rules
composer require wnikk/laravel-access-ui
```
To make changes to the _Wnikk_ package, we'll need to run a command that generates configuration files, migration files, and view files. By following this step, you'll be able to customize the package to meet the specific requirements of your Laravel application:
```bash
php artisan vendor:publish --provider="Wnikk\\LaravelAccessRules\\AccessRulesServiceProvider"
php artisan vendor:publish --provider="Wnikk\\LaravelAccessUi\\AccessUiServiceProvider"
```

## Step 3: Update User Model
We will now integrate **ACR** with our existing user model. This step is important to ensure that our Laravel application has proper access control in place We just need to add **HasPermissions trait** in it:
```php
use Wnikk\LaravelAccessRules\Traits\HasPermissions;

class User extends Model {
    // The User model requires this trait
    use HasPermissions;
```

## Step 4: Configure database connection
For the purpose of this example, we'll be using an SQLite file database. To get started, create an empty file named '_./database/database.sqlite_' and configure the database connection as shown in the example provided.
File .env:
```env
DB_CONNECTION=sqlite
```
At this point, we're ready to run the migration command. By executing this command, we'll be able to create the necessary tables in our SQLite file database, allowing for efficient data management.
```bash
php artisan migrate
```
Now that we have implemented a working access control system with the _ACR package_, the next step is to add permissions to the models in our application. Permissions specify what actions a user with a certain role can perform on a particular resource.


## Step 5: Create News Migration
Moving forward, our next step will be to create a migration for the **news** table. To accomplish this task, execute the following command, which will generate the necessary file and allow us to define the table schema:
```bash
php artisan make:migration create_news_table
```
This will create a new migration file in the _database/migrations_ directory of our Laravel application.  Below you'll find the complete code necessary to define the table's structure, including the various fields and their corresponding types:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->integer('user_id');
            $table->string('name', 70);
            $table->string('description', 320)->nullable();
            $table->text('body');
            $table->softDeletes();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('news');
    }
};
```

We will now re-run the migration:
```bash
php artisan migrate
```

## Step 6: Create model
Now, we will create a news model to retrieve data from the news table. To generate the news model, simply run the following Artisan command. This will create the news model in the _app\Models_ directory.
```bash
php artisan make:model News
```
Example of code for News model:
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class News
 *
 * @property $id
 * @property $user_id
 * @property $name
 * @property $description
 * @property $body
 */
class News extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'body',
    ];
}

```

## Step 7: Create Seeder
Now that we have all the required tables in our database, it's time to fill them with test data and set up **rules** for them.

### 1. Create a few new users:
```bash
php artisan make:seeder CreateUserSeeder
```
Source file _database\seeders\CreateUserSeeder.php_:
```php
<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Test user 1',
            'email' => 'root@mail.com',
            'password' => Hash::make('12345'),
        ]);
        DB::table('users')->insert([
            'id' => 2,
            'name' => 'Test user 2',
            'email' => 'test@mail.com',
            'password' => Hash::make('password'),
        ]);
        DB::table('users')->insert([
            'name' => 'Test user 3',
            'email' => Str::random(10).'@mail.com',
            'password' => Hash::make(Str::random(10)),
        ]);
        DB::table('users')->insert([
            'name' => 'Test user 4',
            'email' => Str::random(10).'@mail.com',
            'password' => Hash::make(Str::random(10)),
        ]);
        DB::table('users')->insert([
            'name' => 'Test user 5',
            'email' => Str::random(10).'@mail.com',
            'password' => Hash::make(Str::random(10)),
        ]);
    }
}
```

### 2. We create multiple news entries.
```bash
php artisan make:seeder NewsTableSeeder
```
Source file _database\seeders\NewsTableSeeder.php_:
```php
<?php
namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\News;

class NewsTableSeeder extends Seeder
{
    public function run(): void
    {
        News::create([
            'user_id' => 1,
            'name' => 'First news',
            'description' => 'Description of first news',
            'body' => 'Body content 1...',
        ]);
        News::create([
            'user_id' => 1,
            'name' => 'Second news',
            'description' => 'Description of second test news',
            'body' => 'Body content 2...',
        ]);
        News::create([
            'user_id' => 2,
            'name' => 'News of test user',
            'body' => 'Body content 3...',
        ]);
    }
}
```

### 3. Multiple **rules** will be added to the system for testing purposes.
```bash
php artisan make:seeder CreateRulesSeeder
```
The rules themselves:
Source file _database\seeders\CreateRulesSeeder.php_:
```php
<?php
namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Wnikk\LaravelAccessRules\AccessRules;

class CreateRulesSeeder extends Seeder
{
    public function run(): void
    {
        // example #1 - route middleware
        AccessRules::newRule('example1.viewAny', 'View all users on example1');

        // example #2 - check in action
        AccessRules::newRule('example2.view', 'View data of user on example2');

        // example #3 - check on action options
        AccessRules::newRule([
            'guard_name' => 'example3.update',
            'title' => 'Changing different user data on example3',
            'options' => 'required|in:name,email,password'
        ]);

        // example #4 - global resource
        AccessRules::newRule('viewAny', 'Global rule "viewAny" for example4');
        AccessRules::newRule('view', 'Global rule "view" for example4');
        AccessRules::newRule('create', 'Global rule "create" for example4');
        AccessRules::newRule('update', 'Global rule "update" for example4');
        AccessRules::newRule('delete', 'Global rule "delete" for example4');

        // example #5 - resource for controller
        AccessRules::newRule('Examples.Example5.viewAny', 'Rule for one Controller his action "viewAny" example5');
        AccessRules::newRule('Examples.Example5.view', 'Rule for one Controller his action "view" example5');
        AccessRules::newRule('Examples.Example5.create', 'Rule for one Controller his action "create" example5');
        AccessRules::newRule('Examples.Example5.update', 'Rule for one Controller his action "update" example5');
        AccessRules::newRule('Examples.Example5.delete', 'Rule for one Controller his action "delete" example5');

        // example #6 - magic self
        AccessRules::newRule(
            'example6.update',
            'Rule that allows edit all news',
        'An example of how to use a magic suffix ".self" on example6'
        );
        AccessRules::newRule('example6.update.self', 'Rule that allows edit only where user is author');

        // example #7 - Policy
        AccessRules::newRule('Example7News.test', 'Rule event "test" example7');

        // Final example, add control to the Access user interface
        $id = AccessRules::newRule('Examples.UserRules.main', 'View all rules, permits and inheritance');
        AccessRules::newRule('Examples.UserRules.rules', 'Working with Rules', null, $id, 'nullable|in:index,store,update,destroy');
        AccessRules::newRule('Examples.UserRules.roles', 'Working with Roles', null, $id, 'nullable|in:index,store,update,destroy');
        AccessRules::newRule('Examples.UserRules.inherit', 'Working with Inherit', null, $id, 'nullable|in:index,store,destroy');
        AccessRules::newRule('Examples.UserRules.permission', 'Working with Permission', null, $id, 'nullable|in:index,update');
    }
}
```
### 4. We are now creating the **super administrator role**.
From which all other user roles will inherit. In this step, it's essential to set the three types of models that can have permissions in the default settings file (**config/access.php**) groups, roles, and users. For the super administrator, we will use roles:
```bash
php artisan make:seeder CreateRootAdminRoleSeeder
```
Permits for each model can be controlled through an owner associated with it.
Source file _database\seeders\CreateRootAdminRoleSeeder.php_:
```php
<?php
namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Wnikk\LaravelAccessRules\AccessRules;

class CreateRootAdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        $acr = new AccessRules;
        $acr->newOwner('Role', 'root', 'RootAdmin role');

        // For example #1
        $acr->addPermission('example1.viewAny');

        // For example #2
        $acr->addPermission('example2.view');

        // For example #3
        $acr->addPermission('example3.update', 'name');
        $acr->addPermission('example3.update', 'email');
        $acr->addPermission('example3.update', 'password');

        // For example #4
        $acr->addPermission('viewAny');
        $acr->addPermission('view');
        $acr->addPermission('create');
        $acr->addPermission('update');
        $acr->addPermission('delete');

        // For example #5
        $acr->addPermission('Examples.Example5.viewAny');
        $acr->addPermission('Examples.Example5.view');
        $acr->addPermission('Examples.Example5.create');
        $acr->addPermission('Examples.Example5.update');
        $acr->addPermission('Examples.Example5.delete');

        // For example #6
        //For all - $acr->addPermission('example6.update');
        $acr->addPermission('example6.update.self');

        // For example #7
        $acr->addPermission('Example7News.test');

        // For final example
        $acr->addPermission('Examples.UserRules.index');
        $acr->addPermission('Examples.UserRules.rules');
        $acr->addPermission('Examples.UserRules.rules', 'index');
        $acr->addPermission('Examples.UserRules.rules', 'store');
        $acr->addPermission('Examples.UserRules.rules', 'update');
        $acr->addPermission('Examples.UserRules.rules', 'destroy');
        $acr->addPermission('Examples.UserRules.roles');
        $acr->addPermission('Examples.UserRules.roles', 'index');
        $acr->addPermission('Examples.UserRules.roles', 'store');
        $acr->addPermission('Examples.UserRules.roles', 'update');
        $acr->addPermission('Examples.UserRules.roles', 'destroy');
        $acr->addPermission('Examples.UserRules.inherit');
        $acr->addPermission('Examples.UserRules.inherit', 'index');
        $acr->addPermission('Examples.UserRules.inherit', 'store');
        $acr->addPermission('Examples.UserRules.inherit', 'destroy');
        $acr->addPermission('Examples.UserRules.permission');
        $acr->addPermission('Examples.UserRules.permission', 'index');
        $acr->addPermission('Examples.UserRules.permission', 'update');
    }
}
```

### 5. And finally, we will add inheritance of permissions from the super administrator to all users.
```bash
php artisan make:seeder AddRoleToAllUserSeeder
```
Source file _database\seeders\AddRoleToAllUserSeeder.php_:
```php
<?php
namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class AddRoleToAllUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $all = User::all();
        foreach ($all as $one) $one->inheritPermissionFrom('Role', 'root');

        // or
        // $acr = new AccessRules;
        // $acr->setOwner('Role', 'root');
        // foreach ($all as $one) $one->inheritPermissionFrom($acr);

        // or
        // $mainUser = User::find(1);
        // foreach ($all as $one) $one->inheritPermissionFrom($mainUser);
    }
}
```
### Let's now move on to importing all the instructions created in this step:
```bash
php artisan db:seed --class=CreateUserSeeder
php artisan db:seed --class=NewsTableSeeder
php artisan db:seed --class=CreateRulesSeeder
php artisan db:seed --class=CreateRootAdminRoleSeeder
php artisan db:seed --class=AddRoleToAllUserSeeder
```
To perform the same access control and inheritance manipulations discussed above,
an alternative method is to use the interface which was added at the beginning of this article.
You can access the interface by opening the address "**/accessui/**" in your project:

List all roles, group and user:
![List all roles, group and user](https://dev-to-uploads.s3.amazonaws.com/uploads/articles/wd3u7u9g6hhc309a5bsx.png)

List all rules:
![List all rules](https://dev-to-uploads.s3.amazonaws.com/uploads/articles/aid56d6pq8mljorm6vzl.png)

# Let's move on to the most interesting part ☕
Various access verification methods and the rules associated with them.
Going forward, the controllers used in the examples will serve as sources of _JSON data for the SPA Frontend_, thus eliminating the need to create templates.
Anyway, we can always view the outcome of rule verification.

## Example 1
In this example, we are using middleware on routing to limit access to the controller.

Apped to file _routes\web.php_:
```php
Route::get('/example1', [Example1Controller::class, 'index'])->middleware('can:example1.viewAny');
```
The controller is not utilized in this example
Apped to file _...Example1Controller.php_:
```php
<?php
namespace App\Http\Controllers\Examples;

use Illuminate\Support\Facades\Response;
use App\Http\Controllers\Controller;
use App\Models\User;

class Example1Controller extends Controller
{
    public function index()
    {
        return Response::json(User::all(), 200);
    }
}
```
Check what happened:
![Example1 Response](https://dev-to-uploads.s3.amazonaws.com/uploads/articles/7xwvxwsd8wfymerzuj6f.png)

## Example 2
Let's try to check the **permission** in the action itself
Apped to file _routes\web.php_:
```php
Route::get('/example2', [Example2Controller::class, 'show']);
```
The controller is not utilized in this example
Source to file _...Example1Controller.php_:
```php
<?php
namespace App\Http\Controllers\Examples;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Gate;
use App\Http\Controllers\Controller;

class Example2Controller extends Controller
{
    public function show()
    {
        Gate::authorize('example2.view');
        
        return Response::json(Auth::user()->toArray(), 200);
    }
}
```
As in an example, you can use all the capabilities of the **Laravel Gate facade**.


## Example 3
This is quite similar to the previous example, but with a separate list of options.
Apped to file _routes\web.php_:
```php
Route::any('/example3/{frm}', [Example3Controller::class, 'update']);
```
Source file _...Example3Controller.php_:
```php
<?php
namespace App\Http\Controllers\Examples;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;
use App\Enum\UserProfileFormEnum;

class Example3Controller extends Controller
{
    public function update(UserProfileFormEnum $frm, Request $request)
    {
        // Add the check by indicating after the point of the [Option] field
        Gate::authorize('example3.update.'.$frm->value);

        $user = Auth::user();
        switch ($frm)
        {
            case(UserProfileFormEnum::Name):

                if($request->name) $user->fill( $request->only(['name']) );

            break;
            case(UserProfileFormEnum::Password):

                if($request->password) $user->password = Hash::make($request->password);

            break;
            case(UserProfileFormEnum::Email):

                $validator = Validator::make($request->all(), [
                    'email' => 'required|email',
                ]);
                if ($validator->fails()) abort('403', $validator->messages());

                $user->email = $request->email;

            break;
        }

        return Response::json($user->save(), 200);
    }
}
```
Why does this behavior occur and how does the **"Option"** differ from the _standard rule_ definition?

It is worth noting that the "Option" field is not linked to the rule, but to the permission itself.
This is done to allow the creation of **multiple permissions within a single rule**. For instance,
it's possible to retrieve all records by ID that have access without having to create separate tables or fields.


## Example 4
In this example, we will utilize the built-in **$this->authorizeResource()** function which comes with the resource feature. This function is very convenient as it automatically creates checks for each action against the following rules: _"viewAny", "view", "create", "update" and "delete"_.
Apped to file _routes\web.php_:
```php
Route::apiResource('example4', Example4Controller::class)->parameters([
    'example4' => 'news'
]);
```
Source file _...Example4Controller.php_:
```php
<?php
namespace App\Http\Controllers\Examples;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use App\Http\Controllers\Controller;
use App\Models\News;

class Example4Controller extends Controller
{
    /**
     * Create the controller instance.
     */
    public function __construct()
    {
        $this->authorizeResource(News::class, 'News');
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Response::json(News::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $news = News::create($request->toArray());

        return Response::json($news->id, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(News $news)
    {
        return Response::json($news->toArray());
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, News $news)
    {
        $news->fill($request->toArray());

        return Response::json($news->save);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(News $news)
    {
        return Response::json($news->delete());
    }
}
```

## Example 5
In the previous example, we used **global rules**, which is not very convenient. To overcome this, we can make functionality for creating rules for each controller separately.

To achieve this, we need to modify the main controller, For that we will create trait.
Source file _App\Http\Traits\GuardedController.php_:
```php
<?php
namespace App\Http\Traits;

use App\Http\Controllers\Controller;

trait GuardedController
{
    /**
     * Map of resource methods to ability names
     * @example ['index' => 'viewAny']
     *
     * @var string[]
     */
    //abstract protected $guardedMethods = [];

    /**
     * Do not automatically scan all available methods.
     *
     * @var bool
     */
    //abstract protected $disableAutoScanGuard = true;

    /**
     * List of resource methods which do not have model parameters.
     * @example ['index']
     *
     * @var string[]
     */
    //abstract protected $methodsWithoutModels = ['index'];

    /**
     * Get the map of resource methods to ability names.
     *
     * @return array
     */
    protected function resourceAbilityMap()
    {
        if (empty($this->disableAutoScanGuard)) {
            $methods = array_diff(
                get_class_methods($this),
                get_class_methods(Controller::class)
            );
            $map = array_combine($methods, $methods);
        } else {
            $map = [];
        }

        $map = array_merge($map, parent::resourceAbilityMap());
        $map = array_merge($map, $this->guardedMethods??[]);

        // Replace name for class App\Http\Controllers\Examples\Example1Controller
        // to guard prefix "Examples.Example1."
        $name = $this->getClassNameGate();

        // Replace standard rule "viewAny" to "Examples.Example1.viewAny"
        foreach ($map as &$item) {$item = $name.$item;}
        unset($item);

        return $map;
    }

    /**
     * Get the list of resource methods which do not have model parameters.
     *
     * @return array
     */
    protected function resourceMethodsWithoutModels()
    {
        $base = parent::resourceMethodsWithoutModels();

        return array_merge($base, $this->methodsWithoutModels??[]);
    }

    /**
     * Get name off class witch namespace for guard
     *
     * @param string|null $action
     * @return string
     */
    protected static function getClassNameGate(?string $action = null): string
    {
        // Replace name for class App\Http\Controllers\Examples\Example1Controller
        // to guard prefix "Examples.Example1."
        $name = str_replace([
            dirname(__TRAIT__, 2).DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR,
            'Controller'
        ], [
            '', '.', '.'
        ], static::class);

        return $name.$action;
    }
}
```
Controller and its example remain exactly the same as in the previous one.
Only added trait (File _...Example5Controller.php_) :
```php
<?php
namespace App\Http\Controllers\Examples;
...
use App\Http\Traits\GuardedController;

class Example5Controller extends Controller
{
    use GuardedController;

    public function __construct()
    {
        $this->authorizeResource(News::class, 'News');
    }
...
```
But the error is already different:
![Example5 Response](https://dev-to-uploads.s3.amazonaws.com/uploads/articles/djdltpwbwdb8df6mx3e1.png)

Thus, with minimal modifications to the existing code,
one can easily incorporate **dynamic access control** capabilities.


## Example 6
Here is a fairly simple example that is similar to the second one.
Through a few subtle differences, there is a **magical** behavior present here.
Apped to file _routes\web.php_:
```php
Route::any('/example6/{news}', [Example6Controller::class, 'update']);
```
Source file _...Example6Controller.php_:
```php
<?php
namespace App\Http\Controllers\Examples;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Gate;
use App\Http\Controllers\Controller;
use App\Models\News;

class Example6Controller extends Controller
{
    public function update(Request $request, News $news)
    {
        Gate::authorize('example6.update', $news);

        $news->fill($request->toArray());

        return Response::json($news->save?1:0);
    }
}
```
Let's take a closer look at this. If we have rule "**example6.update.self**",
we need to specify to the system to check rule "**example6.update**" and added "**.self**" pass the record object to be checked under the hood of ACR.

ACR work will look like this:
```php
if (
    Gate::allows('example6.update')
    && $user->id === $news->user_id
) {
    return true;
}
```

Additionally, it's worth noting that if we are not checking the user but another entity, such as a **moderator**,
**ACR ** keeps track of it, and the check will look like this under the hood:
```php
$moderator = App\Models\Moderator::find('...');
if (
    Gate::forUser($moderator)->allows('example6.update')
    && $moderator->uuid === $news->moderator_uuid
) {
    return true;
}

```
There is no need to perform such checks anymore, as the **magic method** will handle them **automatically**.


## Example 7
Although this is not **ABAC**, the necessary attribute-based access control functionality can be achieved by adding the use of Laravel's built-in policy mechanism.

All the previous examples focused on checking access to the controller, but we can use the same approach in "**Policy**" to implement _access control for attributes_ with all their variations.
For this, it is necessary to generate a policy for our model:
```bash
php artisan make:policy NewsPolicy –model=News
```
Source file _...NewsPolicy.php_:
```php
<?php
namespace App\Policies;

use App\Models\News;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NewsPolicy
{
    public function availableUpdateOnSomeTime(User $user, News $news): ?bool
    {
        if(
            $user->can('Example7News.allowedEditLast24Hours', $news)
            && stripos($user->name, 'author') !== false
            && ($news->created_at->isToday() || $news->created_at->isYesterday())
        ) {
            return true;
        }
        return null;
    }
}
```
After that, it will be necessary to update in the **AuthServiceProvider class** $policies, as written below:
```php
protected $policies = [
    News::class => NewsPolicy::class,
];
```
Now check policy in the controller:
Source file _...Example7Controller.php_:
```php
<?php
namespace App\Http\Controllers\Examples;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use App\Http\Controllers\Controller;
use App\Models\News;

class Example7Controller extends Controller
{

    public function index(News $news)
    {
        $this->authorize('availableUpdateOnSomeTime', $news);

        return Response::json($news->toArray());
    }
}
```
Therefore, we now have the capability to check not only the rules individually, but also to verify the **attributes of the model**.

It is important - if you add rule "_availableUpdateOnSomeTime_" and permission to user, then the **policy will not be checked**.

## Final example

In its default state, the **AccessUi interface** does not include any checks regarding the level of access granted.
To address this, we can create a proxy controller in this example that will verify **all the permissions before** any data manipulation is done.
First, turn off the standard AccessUi routes,
File config/accessUi.php:
```php
    /**
     * Panel Register
     *
     * This manages if routes used for the admin panel should be registered.
     * Turn this value to false if you don't want to use admin panel
     */
    'register' => false,
```

Then, we will create 2 controllers: "_UserRulesController_" and "_UserProfileController_", which uses Trait "_RunsAnotherController_" to run the other **AccessUi controllers**.
The implementation itself is view in files "_user-rules.blade.php_" and "_user-profile.blade.php_".
The files may be a bit lengthy, but they can be viewed separately [in the repository](https://github.com/wnikk/-laravel-access-example).

As a result, we will have individual pages in our style with access rights verification:

Authorized user profile page:
![This user profile page](https://dev-to-uploads.s3.amazonaws.com/uploads/articles/knfpi0tt044k7r4a9n6f.png)

Rules list and **only roles** (hidden user on owner page) list:
![Rules list and only roles list](https://dev-to-uploads.s3.amazonaws.com/uploads/articles/mn1b9qwidbuoc0up7b1u.png)

In conclusion, implementing "[**wnikk/laravel-access-rules**](https://github.com/wnikk/laravel-access-rules)" (ACR, ACL, RBAC)
using  in Laravel is a powerful way to ensure that users are granted access to only the resources
they are authorized to use.
With the help of Laravel's built-in middleware and authorization features,
it is possible to easily create and manage complex access control policies at both
the global and controller-specific levels.
By using _Access-Control-Rules_, developers can add dynamic access control capabilities
to their Laravel applications with minimal code changes,
while ensuring that the application remains secure and easy to maintain.
