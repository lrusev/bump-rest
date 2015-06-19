<?php
namespace Bump\RestBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use Bump\AreaBriefBundle\Entity\Setting;

class SettingModifiedEvent extends Event
{
    protected $setting;

    public function __construct(Setting $setting)
    {
        $this->setting = $setting;
    }

    public function getSetting()
    {
        return $setting;
    }
}