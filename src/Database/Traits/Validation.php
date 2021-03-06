<?php namespace October\Rain\Database\Traits;

use Lang;
use Input;
use October\Rain\Database\ModelException;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Facades\Validator;
use Exception;

trait Validation
{
    /**
     * @var array The rules to be applied to the data.
     *
     * public $rules = [];
     */

    /**
     * @var array The array of custom attribute names.
     *
     * public $attributeNames = [];
     */

    /**
     * @var array The array of custom error messages.
     *
     * public $customMessages = [];
     */

    /**
     * @var bool Makes the validation procedure throw an {@link October\Rain\Database\ModelException}
     * instead of returning false when validation fails.
     *
     * public $throwOnValidation = true;
     */

    /**
     * @var \Illuminate\Support\MessageBag The message bag instance containing validation error messages
     */
    protected $validationErrors;

    /**
     * Boot the validation trait for this model.
     *
     * @return void
     */
    public static function bootValidation()
    {
        if (!property_exists(get_called_class(), 'rules'))
            throw new Exception(sprintf('You must define a $rules property in %s to use the Validation trait.', get_called_class()));

        static::extend(function($model){
            $model->bindEvent('model.saveInternal', function($data, $options) use ($model) {
                /*
                 * If forcing the save event, the beforeValidate/afterValidate
                 * events should still fire for consistency. So validate an
                 * empty set of rules and messages.
                 */
                $force = array_get($options, 'force', false);
                if ($force)
                    $valid = $model->validate([], []);
                else
                    $valid = $model->validate();

                if (!$valid)
                    return false;

            }, 500);
        });

        static::validating(function($model) {
            $model->fireEvent('model.beforeValidate');
            if ($model->methodExists('beforeValidate'))
                $model->beforeValidate();
        });

        static::validated(function($model) {
            $model->fireEvent('model.afterValidate');
            if ($model->methodExists('afterValidate'))
                $model->afterValidate();
        });
    }

    /**
     * Instantiates the validator used by the validation process, depending if the class is being used inside or
     * outside of Laravel.
     * @return \Illuminate\Validation\Validator
     */
    protected static function makeValidator($data, $rules, $customMessages, $attributeNames)
    {
        return Validator::make($data, $rules, $customMessages, $attributeNames);
    }

    /**
     * Force save the model even if validation fails.
     * @return bool
     */
    public function forceSave(array $data = null, $sessionKey = null)
    {
        $this->sessionKey = $sessionKey;
        return $this->saveInternal($data, ['force' => true]);
    }

    /**
     * Validate the model instance
     * @return bool
     */
    public function validate($rules = null, $customMessages = null, $attributeNames = null)
    {
        if ($this->validationErrors === null) {
            $this->validationErrors = new MessageBag;
        }

        $throwOnValidation = property_exists($this, 'throwOnValidation')
            ? $this->throwOnValidation
            : true;

        if ($this->fireModelEvent('validating') === false) {
            if ($throwOnValidation) {
                throw new ModelException($this);
            }
            else {
                return false;
            }
        }

        /*
         * Perform validation
         */
        $rules = (is_null($rules)) ? $this->rules : $rules;
        $rules = $this->processValidationRules($rules);
        $success = true;

        if (!empty($rules)) {

            $data = $this->getAttributes();

            /*
             * Add relation values, if specified.
             */
            foreach ($rules as $attribute => $rule) {
                if (!$this->hasRelation($attribute)) continue;
                if (array_key_exists($attribute, $data)) continue;
                $data[$attribute] = $this->getRelationValue($attribute);
            }

            /*
             * Compatability with Hashable trait:
             * Remove all hashable values regardless, add the original values back
             * only if they are part of the data being validated.
             */
            if (method_exists($this, 'getHashableAttributes')) {
                $cleanAttributes = array_diff_key($data, array_flip($this->getHashableAttributes()));
                $hashedAttributes = array_intersect_key($this->getOriginalHashValues(), $data);
                $data = array_merge($cleanAttributes, $hashedAttributes);
            }

            /*
             * Compatability with Encryptable trait:
             * Remove all encryptable values regardless, add the original values back
             * only if they are part of the data being validated.
             */
            if (method_exists($this, 'getEncryptableAttributes')) {
                $cleanAttributes = array_diff_key($data, array_flip($this->getEncryptableAttributes()));
                $encryptedAttributes = array_intersect_key($this->getOriginalEncryptableValues(), $data);
                $data = array_merge($cleanAttributes, $encryptedAttributes);
            }

            /*
             * Custom messages, translate internal references
             */
            if (property_exists($this, 'customMessages') && is_null($customMessages)) {
                $customMessages = $this->customMessages;
            }

            if (is_null($customMessages)) {
                $customMessages = [];
            }

            $translatedCustomMessages = [];
            foreach ($customMessages as $rule => $customMessage){
                $translatedCustomMessages[$rule] = Lang::get($customMessage);
            }

            $customMessages = $translatedCustomMessages;

            /*
             * Attribute names, translate internal references
             */
            if (is_null($attributeNames)) {
                $attributeNames = [];
            }

            if (property_exists($this, 'attributeNames')) {
                $attributeNames = array_merge($this->attributeNames, $attributeNames);
            }

            $translatedAttributeNames = [];
            foreach ($attributeNames as $attribute => $attributeName){
                $translatedAttributeNames[$attribute] = Lang::get($attributeName);
            }

            $attributeNames = $translatedAttributeNames;

            /*
             * Translate any externally defined attribute names
             */
            $translations = Lang::get('validation.attributes');
            if (is_array($translations)) {
                $attributeNames = array_merge($translations, $attributeNames);
            }

            /*
             * Hand over to the validator
             */
            $validator = self::makeValidator($data, $rules, $customMessages, $attributeNames);

            $success = $validator->passes();

            if ($success) {
                if ($this->validationErrors->count() > 0)
                    $this->validationErrors = new MessageBag;
            }
            else {
                $this->validationErrors = $validator->messages();
                if (Input::hasSession()) Input::flash();
            }
        }

        $this->fireModelEvent('validated', false);

        if (!$success && $throwOnValidation) {
            throw new ModelException($this);
        }

        return $success;
    }

    /**
     * Process rules
     */
    protected function processValidationRules($rules)
    {
        foreach ($rules as $field => $ruleParts) {
            /*
             * Trim empty rules
             */
            if (is_string($ruleParts) && trim($ruleParts) == '') {
                unset($rules[$field]);
                continue;
            }

            /*
             * Normalize rulesets
             */
            if (!is_array($ruleParts))
                $ruleParts = explode('|', $ruleParts);

            /*
             * Analyse each rule individually
             */
            foreach ($ruleParts as $key => $rulePart) {
                /*
                 * Remove primary key unique validation rule if the model already exists
                 */
                if (starts_with($rulePart, 'unique') && $this->exists) {
                    $ruleParts[$key] = 'unique:'.$this->getTable().','.$field.','.$this->getKey();
                }
                /*
                 * Look for required:create and required:update rules
                 */
                else if (starts_with($rulePart, 'required:create') && $this->exists) {
                    unset($ruleParts[$key]);
                }
                else if (starts_with($rulePart, 'required:update') && !$this->exists) {
                    unset($ruleParts[$key]);
                }
            }

            $rules[$field] = $ruleParts;
        }

        return $rules;
    }

    /**
     * Determines if an attribute is required based on the validation rules.
     * @param  string  $attribute
     * @return boolean
     */
    public function isAttributeRequired($attribute)
    {
        if (!isset($this->rules[$attribute]))
            return false;

        $ruleset = $this->rules[$attribute];

        if (is_array($ruleset))
            $ruleset = implode('|', $ruleset);

        if (strpos($ruleset, 'required:create') !== false && $this->exists)
            return false;

        if (strpos($ruleset, 'required:update') !== false && !$this->exists)
            return false;

        if (strpos($ruleset, 'required_with') !== false) {
            $requiredWith = substr($ruleset, strpos($ruleset, 'required_with') + 14);
            $requiredWith = substr($requiredWith, 0, strpos($requiredWith, '|'));
            return $this->isAttributeRequired($requiredWith);
        }

        return strpos($ruleset, 'required') !== false;
    }

    /**
     * Get validation error message collection for the Model
     * @return \Illuminate\Support\MessageBag
     */
    public function errors()
    {
        return $this->validationErrors;
    }

    /**
     * Create a new native event for handling beforeValidate().
     * @param Closure|string $callback
     * @return void
     */
    public static function validating($callback)
    {
        static::registerModelEvent('validating', $callback);
    }

    /**
     * Create a new native event for handling afterValidate().
     * @param Closure|string $callback
     * @return void
     */
    public static function validated($callback)
    {
        static::registerModelEvent('validated', $callback);
    }

}
