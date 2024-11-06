<?php

declare(strict_types=1);

namespace gameparrot\doubleslabs\listener;

use gameparrot\doubleslabs\DoubleSlabs;
use pocketmine\block\Slab;
use pocketmine\block\utils\SlabType;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\PalettedBlockArray;
use pocketmine\world\format\SubChunk;
use pocketmine\world\sound\BlockPlaceSound;
use ReflectionProperty;
use function count;
use function reset;
use function spl_object_id;

class DoubleSlabsListener implements Listener {
	private ReflectionProperty $emptyIdRefl;
	private ReflectionProperty $layersRefl;
	public function __construct(private DoubleSlabs $doubleSlabs) {
		$this->layersRefl = new ReflectionProperty(SubChunk::class, "blockLayers");
		$this->emptyIdRefl = new ReflectionProperty(SubChunk::class, "emptyBlockId");
	}

	public function onInteract(PlayerInteractEvent $event) : void {
		if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
			return;
		}
		$player = $event->getPlayer();
		$heldBlock = $player->getInventory()->getItemInHand()->getBlock($event->getFace());
		if (!$heldBlock instanceof Slab) {
			return;
		}
		if ($heldBlock->getSlabType() === SlabType::DOUBLE()) {
			return;
		}

		$block = $event->getBlock();
		if (!$block instanceof Slab || ($event->getFace() !== Facing::DOWN && $event->getFace() !== Facing::UP)) {
			$block = $block->getSide($event->getFace());
			if (!$block instanceof Slab) {
				return;
			}
		} else {
			$otherBlock = $block->getSide($event->getFace(), -1);
			if ($otherBlock instanceof Slab && $block->getSlabType() !== SlabType::DOUBLE() && $block->getTypeId() === $heldBlock->getTypeId() && $heldBlock->getTypeId() === $otherBlock->getTypeId()) {
				$subChunk = $player->getWorld()->getChunk($otherBlock->getPosition()->x >> Chunk::COORD_BIT_SIZE, $otherBlock->getPosition()->z >> Chunk::COORD_BIT_SIZE)->getSubChunk($otherBlock->getPosition()->y >> Chunk::COORD_BIT_SIZE);
				$layers = $subChunk->getBlockLayers();
				if (isset($layers[1]) && ($existingState = $layers[1]->get($otherBlock->getPosition()->x, $otherBlock->getPosition()->y, $otherBlock->getPosition()->z)) !== $this->emptyIdRefl->getValue($subChunk)) {
					$this->sendUpdateBlock($otherBlock->getPosition()->x, $otherBlock->getPosition()->y, $otherBlock->getPosition()->z, Server::getInstance()->getOnlinePlayers(), $existingState, UpdateBlockPacket::DATA_LAYER_LIQUID);
					$event->cancel();
					return;
				}
			}
		}

		$isBottomSlab = $block->getSlabType() === SlabType::BOTTOM();
		$heldBlock->setSlabType($isBottomSlab ? SlabType::TOP() : SlabType::BOTTOM());

		/** @var int */
		$x = $block->getPosition()->x;
		/** @var int */
		$y = $block->getPosition()->y;
		/** @var int */
		$z = $block->getPosition()->z;

		$heldBlock->position($player->getWorld(), $x, $y, $z);
		foreach ($heldBlock->getCollisionBoxes() as $collisionBox) {
			if (count($player->getWorld()->getCollidingEntities($collisionBox)) > 0) {
				return;  //Entity in block
			}
		}

		$subChunk = $player->getWorld()->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)->getSubChunk($y >> Chunk::COORD_BIT_SIZE);
		$layers = $subChunk->getBlockLayers();
		if (!isset($layers[1])) {
			$layers[1] = new PalettedBlockArray($this->emptyIdRefl->getValue($subChunk));
			$this->layersRefl->setValue($subChunk, $layers);
		}

		if ($block->getTypeId() === $heldBlock->getTypeId()) {
			if (($existingState = $layers[1]->get($x, $y, $z)) !== $this->emptyIdRefl->getValue($subChunk)) {
				$event->cancel();
				$isBelow = $block->getSlabType() === SlabType::TOP();
				$heldBlock->setSlabType($block->getSlabType());
				$player->getWorld()->setBlockAt($x, $y + ($isBelow ? -1 : 1), $z, $heldBlock);
				$player->getWorld()->addSound($block->getPosition()->add(0, $isBelow ? -1 : 1, 0), new BlockPlaceSound($heldBlock));
				$this->sendUpdateBlock($x, $y, $z, Server::getInstance()->getOnlinePlayers(), $existingState, UpdateBlockPacket::DATA_LAYER_LIQUID);
			}
			return;
		}

		if ($layers[1]->get($x, $y, $z) !== $this->emptyIdRefl->getValue($subChunk) || !$player->isSneaking()) {
			return;
		}

		$stateId = $heldBlock->getStateId();
		$layers[1]->set($x, $y, $z, $stateId);
		$this->sendUpdateBlock($x, $y, $z, $player->getServer()->getOnlinePlayers(), $stateId, UpdateBlockPacket::DATA_LAYER_LIQUID);
		$player->getWorld()->addSound($block->getPosition(), new BlockPlaceSound($block));
		$event->cancel();
	}

	public function onBlockBreak(BlockBreakEvent $event) : void {
		$block = $event->getBlock();
		$player = $event->getPlayer();
		if ($block instanceof Slab && $block->getSlabType() !== SlabType::DOUBLE()) {
			$x = $block->getPosition()->x;
			$y = $block->getPosition()->y;
			$z = $block->getPosition()->z;
			$subChunk = $player->getWorld()->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)->getSubChunk($y >> Chunk::COORD_BIT_SIZE);
			$layers = $subChunk->getBlockLayers();
			if (isset($layers[1])) {
				$layers[1]->set($x, $y, $z, $this->emptyIdRefl->getValue($subChunk));
			}
		}
	}

	public function sendUpdateBlock(int $x, int $y, int $z, array $players, int $stateId, int $layer) : void {
		$blockPos = new BlockPosition($x, $y, $z);
		$blockTranslators = [];
		foreach ($players as $player) {
			if (!$player->isConnected()) {
				continue;
			}
			$blockTranslator = $player->getNetworkSession()->getTypeConverter()->getBlockTranslator();
			$translatorId = spl_object_id($blockTranslator);
			if (!isset($blockTranslators[$translatorId])) {
				$blockTranslators[$translatorId] = [$blockTranslator, [$player]];
			} else {
				$blockTranslators[$translatorId][1][] = $player;
			}
		}
		foreach ($blockTranslators as $blockTranslator) {
			NetworkBroadcastUtils::broadcastPackets($blockTranslator[1], [UpdateBlockPacket::create($blockPos, $blockTranslator[0]->internalIdToNetworkId($stateId), UpdateBlockPacket::FLAG_NETWORK, $layer)]);
		}
	}

	public function onPacketSend(DataPacketSendEvent $event) : void {
		foreach ($event->getPackets() as $packet) {
			if ($packet->pid() === UpdateBlockPacket::NETWORK_ID) {
				/** @var UpdateBlockPacket $packet */
				if ($packet->dataLayerId !== UpdateBlockPacket::DATA_LAYER_NORMAL) {
					continue;
				}
				$targets = $event->getTargets();
				$world = reset($targets)->getPlayer()?->getWorld();
				if ($world === null) {
					continue;
				}
				$x = $packet->blockPosition->getX();
				$y = $packet->blockPosition->getY();
				$z = $packet->blockPosition->getZ();
				$subChunk = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)->getSubChunk($y >> Chunk::COORD_BIT_SIZE);
				$layers = $subChunk->getBlockLayers();
				if (isset($layers[1]) && ($existingState = $layers[1]->get($x, $y, $z)) !== $this->emptyIdRefl->getValue($subChunk)) {
					$this->doubleSlabs->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($x, $y, $z, $existingState, $targets) : void {
						$players = [];
						foreach ($targets as $target) {
							if ($target->getPlayer() !== null) {
								$players[] = $target->getPlayer();
							}
						}
						$this->sendUpdateBlock($x, $y, $z, $players, $existingState, UpdateBlockPacket::DATA_LAYER_LIQUID);
					}), 1);
				}
			}
		}
	}
}
