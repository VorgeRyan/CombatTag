<?php

namespace vorge\CombatTag;

use pocketmine\{
    block\Block,
    entity\Entity,
    entity\projectile\Projectile,
    event\entity\EntityDamageByEntityEvent,
    event\entity\EntityDamageEvent,
    event\entity\ProjectileHitBlockEvent,
    event\entity\ProjectileHitEvent,
    event\Listener,
    event\player\PlayerDeathEvent,
    event\player\PlayerMoveEvent,
    event\player\PlayerQuitEvent,
    item\Item,
    level\Location,
    level\Position,
    network\mcpe\protocol\UpdateBlockPacket,
    Player
};

class EventListener implements Listener{

    /** @var CombatTag $plugin */
    private $plugin;

    /** @var Position[][] $previousBlocks */
    protected $previousBlocks = [];

    public function __construct(CombatTag $plugin){
        $this->plugin = $plugin;
    }

    /**
     * @param PlayerMoveEvent $ev
     * @priority LOWEST
     * @ignoreCancelled TRUE
     */
    public function onPlayerMove(PlayerMoveEvent $ev): void{
        $player = $ev->getPlayer();
        $from = $ev->getFrom();
        $to = $ev->getTo();

        $this->glitchCheck($player);

        if($from->getX() == $to->getX() && $from->getY() == $to->getY() && $from->getZ() == $to->getZ()) return;

        $locations = $this->getWallBlocks($player);

        if(isset($this->previousBlocks[$player->getName()])){
            $removeBlocks = $this->previousBlocks[$player->getName()];
        }else{
            $removeBlocks = [];
        }

        /** @var Location $location */
        foreach($locations as $location){
            if(isset($removeBlocks[$location->__toString()])){
                unset($removeBlocks[$location->__toString()]);
            }
            $block = Block::get(Block::STAINED_GLASS, 14)->setComponents((int)floor($location->getX()), (int)floor($location->getY()), (int)floor($location->getZ()))->setLevel($player->getLevel());
            $player->getLevel()->sendBlocks([$player], [$block], UpdateBlockPacket::FLAG_NETWORK);
        }

        foreach($removeBlocks as $location){
            $location = $location->floor();
            $block = $player->getLevel()->getBlock($location);
            $player->getLevel()->sendBlocks([$player], [$block->setComponents((int)$location->getX(), (int)$location->getY(), (int)$location->getZ())->setLevel($block->getLevel())], UpdateBlockPacket::FLAG_NETWORK);
        }

        $this->previousBlocks[$player->getName()] = $locations;
    }

    /**
     * @param EntityDamageEvent $ev
     * @priority HIGHEST
     * @ignoreCancelled TRUE
     */
    public function onEntityDamage(EntityDamageEvent $ev): void{
        if(!$ev instanceof EntityDamageByEntityEvent) return;

        $victim = $ev->getEntity();
        $damager = $ev->getDamager();


        if(!$victim instanceof Player) return;

        $damager = $this->determineAttacker($victim, $damager);

        if(!$damager instanceof Player) return;

        if($damager->getGamemode() === Player::CREATIVE || $victim->getGamemode() === Player::CREATIVE) return;

        if($victim === $damager) return;

        $this->plugin->getLogger()->debug("Tagging {$victim->getName()} and {$damager->getName()}");
        CombatTag::tag($victim, $damager);
    }

    /**
     * @param PlayerDeathEvent $ev
     * @priority HIGHEST
     * @ignoreCancelled TRUE
     */
    public function onPlayerDeath(PlayerDeathEvent $ev): void{
        $player = $ev->getPlayer();
        if(CombatTag::isTagged($player)){
            CombatTag::unTag($player);
        }
    }

    /**
     * @param PlayerQuitEvent $ev
     * @priority LOWEST
     * @ignoreCancelled TRUE
     */
    public function onPlayerQuit(PlayerQuitEvent $ev): void{
        $player = $ev->getPlayer();
        if(CombatTag::isTagged($player)){
            CombatTag::unTag($player);
            $player->kill();
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
        }
    }

    /**
     * @param ProjectileHitEvent $ev
     * @priority LOWEST
     * @ignoreCancelled TRUE
     */
    public function onProjectileHit(ProjectileHitEvent $ev): void{
        if($ev instanceof ProjectileHitBlockEvent){
            $block = $ev->getBlockHit();
            if($this->plugin->isInSpawn($block)){
                $entity = $ev->getEntity();
                $entity->setOwningEntity(null);
            }
        }
    }

    /**
     * @param Player $player
     * @return array
     */
    private function getWallBlocks(Player $player): array{
        $locations = [];

        if(!CombatTag::isTagged($player)) return $locations;

        $radius = 4;
        $l = $player->getPosition();
        $loc1 = clone $l->add($radius, 0, $radius);
        $loc2 = clone $l->subtract($radius, 0, $radius);
        $maxBlockX = max($loc1->getFloorX(), $loc2->getFloorX());
        $minBlockX = min($loc1->getFloorX(), $loc2->getFloorX());
        $maxBlockZ = max($loc1->getFloorZ(), $loc2->getFloorZ());
        $minBlockZ = min($loc1->getFloorZ(), $loc2->getFloorZ());

        for($x = $minBlockX; $x <= $maxBlockX; $x++){
            for($z = $minBlockZ; $z <= $maxBlockZ; $z++){
                $location = new Position($x, $l->getFloorY(), $z, $l->getLevel());

                if($this->plugin->isInSpawn($location)) continue;

                if(!$this->plugin->isPvpSurrounding($location)) continue;

                for($i = 0; $i <= $radius; $i++){
                    $loc = clone $location;

                    $loc->setComponents($loc->getX(), $loc->getY() + $i, $loc->getZ());

                    if($loc->getLevel()->getBlock($loc)->getId() !== Item::AIR) continue;

                    $locations[$loc->__toString()] = $loc;
                }
            }
        }
        return $locations;
    }

    /**
     * @param Player $victim
     * @param Entity $attackerEntity
     * @return null|Player
     */
    private function determineAttacker(Player $victim, Entity $attackerEntity): ?Player{
        if($attackerEntity instanceof Projectile){
            if($attackerEntity->getOwningEntity() instanceof Entity){
                $source = $attackerEntity->getOwningEntity();
            }else{
                return null;
            }

            if($attackerEntity->getId() == Item::ENDER_PEARL && $attackerEntity === $victim) return null;

            return $this->determineAttacker($victim, $source);
        }elseif($attackerEntity instanceof Player){
            return $attackerEntity;
        }
        return null;
    }

    /**
     * @param Player $player
     */
    private function glitchCheck(Player $player): void{
        if(CombatTag::isTagged($player)){
            if($this->plugin->isInSpawn($player)){
                $player->knockBack($player, 0, -$player->getDirectionVector()->getX(), -$player->getDirectionVector()->getZ(), 1.2);
            }
        }
    }
}