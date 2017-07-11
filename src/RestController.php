<?php

namespace Tuupke\REST;

use Laravel\Lumen\Application as App;
use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use App\User;
use Illuminate\Database\Eloquent\Model;
use Route;

abstract class RestController extends BaseController {

    protected static $model;
    protected static $modelShortName;
    protected static $groupRoot;
    protected $needsParent = false;
    protected static $relations = [];
    protected static $blacklist = [];
    protected static $methodToTypeMap = [
        "list"   => ["get", null],
        "get"    => ["get", null],
        "create" => ["post", null],
        "update" => ["put", null],
        "delete" => ["delete", null],
        "patch"  => ["patch", null],
    ];
    protected static $relationToTypeMap = [
        "get"    => ["get", null],
        "create" => ["post", null],
        "attach" => ["post", "{attachId}"],
        "detach" => ["delete", "{detachId}"],
    ];
    protected static $grouping = [
        "list",
        "create",
        "{id}" => [
            "get",
            "update",
            "delete",
            "patch",
            "%RELATION%" => [
                "get",
                "{relationId}" => [
                    "attach",
                    "detach",
                ],
            ],
        ],
    ];

    private function notFound () {
        abort(404);
    }

    public function list(User $user) {
        return static::$model::paginate(@$_GET['per_page']);
    }

    public function get ($id, User $user) {
        $first = static::$model::find($id);
        if (is_null($first))
            $this->notFound();

        return $first;
    }

    public function create (User $user, Request $request) {
        $instance = null;

        if ($this->needsParent) {
            $attacher = $this->getParentAttacher();

            if (is_null($attacher)) // TODO, is this the proper error?
                $this->notFound();

            $instance = $attacher->call()->create($request->all());
        } else {
            $instance = static::$model::create($request->all());
        }

        if ($instance->isInvalid())
            abort(406, $instance->getErrors());

        $instance->save();

        return response($instance, 201);
    }

    public function update ($id, User $user, Request $request) {
        $instance = static::$model::find($id);

        if (is_null($instance))
            $this->notFound();

        $instance->update($request->all());

        if ($instance->isInvalid())
            abort(406, $instance->getErrors());

        $instance->save();

        return $instance;
    }

    public function delete ($id, User $user, Request $request) {
        $instance = static::$model::find($id);

        if (is_null($instance))
            $this->notFound();

        if ($instance->delete() !== true)
            abort(500, "Something went wrong deleting this static::$modelShortName.");

        return response('', 202);
    }

    public function patch ($id, User $user, Request $request) {
        $instance = static::$model::find($id);

        if (is_null($instance))
            $this->notFound();

//        dd($request->all());
        $instance->update($request->all());

        if ($instance->isInvalid())
            abort(406, $instance->getErrors());

        $instance->save();

        return $instance;
    }

    public function relation (Request $request, User $user) {
        $split = explode("/", $request->decodedPath());
        $method = $request->getRealMethod();
        if ($method == "GET" || $method == "POST") {
            if (count($split) < 2)
                abort(405);

            $relation = array_pop($split);
            $base = array_pop($split);
            $instance = $this->get($base, $user);
            $relation = self::mapRelationToInnerName($relation);

            switch ($method) {
                case "GET":
                    return $this->getRelation($instance, $relation);
                case "POST":
                    return $this->createOverRelation($instance, $relation, $request);
            }
        } else {
            if (count($split) < 3)
                abort(405);

            $top = array_pop($split);
            $relation = array_pop($split);
            $base = array_pop($split);

            $relation = self::mapRelationToInnerName($relation);

            $instance = $this->get($base, $user);
            switch ($method) {
                case "POST":

                    return $this->attach($instance, $relation, $top, $request);
                    break;
                case "DELETE":
                    return $this->detach($instance, $relation, $top, $request);

                    break;
                default:
                    $this->notFound();
                    break;
            }
        }
    }

    private static final function mapRelationToInnerName ($name) {
        if (key_exists($name, static::$relations))
            return static::$relations[$name];
        else if (in_array($name, static::$relations))
            return $name;
        else
            abort(404);
    }

    private static final function isSingle ($relation) : bool {
        switch (get_class($relation)) {
            case \Illuminate\Database\Eloquent\Relations\BelongsTo::class:
            case \Illuminate\Database\Eloquent\Relations\HasOne::class:
            case \Illuminate\Database\Eloquent\Relations\MorphOne::class:
                return true;

            default:
                return false;
        }
    }

    protected function getRelation (Model $instance, string $relation) {
        $rel = $instance->$relation();

        if (self::isSingle($rel))
            return $instance->first();

        return $rel->paginate(@$_GET['per_page']);
    }

    protected function createOverRelation(Model $instance, string $relation, Request $request) {
        $rel = $instance->$relation();

        if (self::isSingle($rel) && !is_null($relation->first()))
            abort(406, "Relation already exists");

        $instance = $rel->create($request->all());


        if ($instance->isInvalid())
            abort(406, $instance->getErrors());

        return $instance;
    }

    protected function attach (Model $instance, string $relation, $identifier, Request $request) {
        $rel = $instance->$relation();

        $obj = $rel->find($identifier);

        if (!is_null($obj))
            abort(406, "Already attached");

        $cls = get_class($rel->getRelated());
        $attachee = $cls::find($identifier);

        if (is_null($attachee))
            $this->notFound();

        $allRequest = $request->all();

        if (self::isSingle($rel)) {
            if ($rel->save($obj, $allRequest) !== true)
                abort(500, "Something went wrong");
        } else if ($rel->attach($obj, $allRequest) !== true)
            abort(500, "Something went wrong");

        return response(202);
    }

    protected function detach (Model $instance, string $relation, $identifier, Request $request) {
        $rel = $instance->$relation();

        $obj = $rel->find($identifier);

        if (is_null($obj))
            $this->notFound();

        if (self::isSingle($rel))
            $rel->disassociate();
        else if ($rel->detach($obj) !== true)
            abort(500, "Something went wrong");

        return response(202);
    }

    protected function getParentAttacher () : ?RelationAttacher {
        return null;
    }

    public static final function getRoutes (App $app, $opts = null, Callable $callback = null) {
        $path = explode('\\', get_called_class());
        $class = array_pop($path);

        $options = [];

        if (!is_null($opts)){ // $callback will also be null then
            if (is_array($opts)) {
                $options = $opts;
            } else if (is_callable($opts)) {
                $callback = $opts;
            }
        }

        // Enforce that we are prefixed
        $options["prefix"] = @$options["prefix"] . strtolower(static::$groupRoute ?? static::$modelShortName);

        $app->group($options, function () use ($app, $class, $callback) {
            if (!is_null($callback)) {
                $callback($app);
            }

            self::createGroup($app, null, static::$grouping, $class);
        });
//        echo "\n";
    }

    private static function createGroup (App $app, $groupName, $groupComposition, $class, $tabs = "") {
//        echo "$tabs\$app->group(['prefix' => '$groupName'], function(){\n";
        $callable = function () use ($app, $groupComposition, $class, $tabs) {
            $tabs .= "\t";
            foreach ($groupComposition as $key => $value) {
                if (!is_numeric($key)) {
                    if ($key == "%RELATION%") {
                        // Add relations
                        foreach (static::$relations as $key => $relation) {
                            if (is_string($key))
                                $relation = $key;

//                            echo "$tabs\$app->group(['prefix' => '$relation'], function(){\n";
                            $app->group(['prefix' => $relation], function () use ($relation, $class, $app, $tabs) {
                                foreach (static::$relationToTypeMap as $type => $value) {
                                    if (in_array("$relation.$type ", static::$blacklist))
                                        continue;

                                    $verb = $value[0];
                                    $path = $value[1];
                                    $app->$verb("$path", "$class@relation");
//                                    echo "$tabs\t\$app->$verb(\"$path\", \"$class@relation\");\n";
                                }
                            });
//                            echo "$tabs}\n";
                        }
                    } else {
                        self::createGroup($app, $key, $value, $class, $tabs);
                    }
                } else {
                    $obj = static::$methodToTypeMap[$value];
                    $verb = $obj[0];
                    $path = $obj[1];

                    if (in_array($value, static::$blacklist))
                        continue;

//                    echo "$tabs\$app->$verb(\"$path\", \"$class@$value\");\n";
                    $app->$verb($path, "$class@$value");
                }
            }
        };

        if (is_null($groupName)){
            $callable();
        } else {
            $app->group(['prefix' => $groupName], $callable);
        }
//        echo "$tabs}\n";
    }
}

class RelationAttacher {

    private $parent;
    private $relation;

    public function parent () : Model {
        return $this->parent;
    }

    public function relation () : string {
        return $this->relation;
    }

    public function call () {
        $methodName = $this->relation();

        return $this->parent()->$methodName();
    }

    private function __construct (Model $model, string $relation) {
        $this->parent = $model;
        $this->relation = $relation;
    }

    static function create (Model $model, string $relation) : RelationAttacher {
        return new RelationAttacher($model, $relation);
    }
}
