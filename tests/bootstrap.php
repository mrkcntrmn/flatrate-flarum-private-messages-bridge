<?php

require __DIR__ . '/../vendor/autoload.php';

// Minimal Illuminate Arr helper is pulled via illuminate/support when available.
// ConversationValidator uses Illuminate\Support\Arr; provide a tiny fallback if
// flarum/core is not installed in this isolated unit-test environment.
if (!class_exists(\Illuminate\Support\Arr::class)) {
    eval(<<<'PHP'
namespace Illuminate\Support;
class Arr {
    public static function get($array, $key, $default = null) {
        if (!is_array($array)) {
            return $default;
        }
        if (is_null($key)) {
            return $array;
        }
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        if (strpos($key, '.') === false) {
            return $default;
        }
        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }
        return $array;
    }
}
PHP);
}

if (!class_exists(\Flarum\User\User::class)) {
    eval(<<<'PHP'
namespace Flarum\User;
class User {
    public $id;
    public function __construct($id = null) {
        $this->id = $id;
    }
    public function isGuest() {
        return empty($this->id);
    }
}
PHP);
}


if (!class_exists(\Flarum\Foundation\ValidationException::class)) {
    eval(<<<'PHP'
namespace Flarum\Foundation;
class ValidationException extends \Exception {
    protected $attributes;
    protected $relationships;
    public function __construct(array $attributes, array $relationships = []) {
        $this->attributes = $attributes;
        $this->relationships = $relationships;
        $messages = [implode("\n", $attributes), implode("\n", $relationships)];
        parent::__construct(implode("\n", $messages));
    }
    public function getAttributes() { return $this->attributes; }
    public function getRelationships() { return $this->relationships; }
}
PHP);
}

if (!class_exists(\Flarum\User\Exception\PermissionDeniedException::class)) {
    eval(<<<'PHP'
namespace Flarum\User\Exception;
class PermissionDeniedException extends \Exception {}
PHP);
}

if (!class_exists(\Flarum\Database\AbstractModel::class)) {
    eval(<<<'PHP'
namespace Flarum\Database;
class AbstractModel {
    public function __construct() {}
}
PHP);
}

if (!class_exists(\Illuminate\Database\Eloquent\ModelNotFoundException::class)) {
    eval(<<<'PHP'
namespace Illuminate\Database\Eloquent;
class ModelNotFoundException extends \RuntimeException {}
PHP);
}


