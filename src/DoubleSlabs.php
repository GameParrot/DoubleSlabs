<?php

declare(strict_types=1);

namespace gameparrot\doubleslabs;

use gameparrot\doubleslabs\listener\DoubleSlabsListener;
use pocketmine\plugin\PluginBase;

final class DoubleSlabs extends PluginBase {
	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents(new DoubleSlabsListener($this), $this);
	}
}
