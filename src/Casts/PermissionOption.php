<?php

namespace Wnikk\LaravelAccessRules\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Validator;
use LogicException;

class PermissionOption implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return mixed
     */
    public function get($model, $key, $value, $attributes)
    {
        return $value;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  string  $value
     * @param  array  $attributes
     * @return string
     */
    public function set($model, $key, $value, $attributes)
    {
        $rules = $model->rule->options;

        if ($rules)
        {
            $validator = Validator::make(
                ['option' => $value],
                ['option' => $rules]
            );

            if ($validator->fails()) {
                throw new LogicException(
                    'Specified option "'.$value.'" does not comply with the permissible rule.'
                );
            }

        } elseif ($value)
        {
            throw new LogicException(
                'Role has no permissible option "'.$value.'". Before adding a permission, adjust rule option validator.'
            );
        }

        return $value;
    }
}
