<?php
namespace modules;
use Craft;
use craft\fields\Dropdown;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;

use yii\base\Event;


// A dropdown color picker from SPH theme colors.
class SphColorField extends Dropdown
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        
        // Cause endless loop.
        $globalTest = Craft::$app->globals->getSetByHandle('globalTest');

        $this->options = [
            [
                'label' => 'Choose a color',
                'value' => '',
                'disabled' => true,
            ],
            [
                'label' => 'Purple',
                'value' => 'purple',
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'SphColor';
    }

}



/**
 * Custom module class.
 *
 * This class will be available throughout the system via:
 * `Craft::$app->getModule('my-module')`.
 *
 * You can change its module ID ("my-module") to something else from
 * config/app.php.
 *
 * If you want the module to get loaded on every request, uncomment this line
 * in config/app.php:
 *
 *     'bootstrap' => ['my-module']
 *
 * Learn more about Yii module development in Yii's documentation:
 * http://www.yiiframework.com/doc-2.0/guide-structure-modules.html
 */
class Module extends \yii\base\Module
{
    /**
     * Initializes the module.
     */
    public function init()
    {
        // Set a @modules alias pointed to the modules/ directory
        Craft::setAlias('@modules', __DIR__);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'modules\\console\\controllers';
        } else {
            $this->controllerNamespace = 'modules\\controllers';
        }

        parent::init();

        // Custom initialization code goes here...
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                Craft::debug(
                    'Fields::EVENT_REGISTER_FIELD_TYPES',
                    __METHOD__
                );
                $event->types[] = SphColorField::class;
            }
        );
    }
}


