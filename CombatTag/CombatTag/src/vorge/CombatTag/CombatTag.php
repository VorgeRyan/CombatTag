<?php

namespace vorge\CombatTag;

use pocketmine\{
    level\Position, plugin\PluginBase, Player
};

class CombatTag extends PluginBase{

    /** @var int[][] $tagged */
    private static $tagged = [];

    /** @var int $tag_time */
    protected static $tag_time;

    public function onEnable(){
        $this->saveResource("config.yml");

        self::$tag_time = $this->getConfig()->get("tag-time");

        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
    }

    /**
     * @param Player $victim
     * @param Player $damager
     */
    public static function tag(Player $victim, Player $damager): void{
        /** @var Player $player */
        foreach([$victim, $damager] as $player){
            self::$tagged[$player->getName()] = ["time" => time()];
        }
    }

    /**
     * @param Player $player
     * @return bool
     */
    public static function isTagged(Player $player): bool{
        if(isset(self::$tagged[$player->getName()])){
            if((self::$tagged[$player->getName()]["time"] + self::$tag_time) <= time()){
                self::unTag($player);
            }
        }
        return isset(self::$tagged[$player->getName()]);
    }

    /**
     * @param Player $player
     */
    public static function unTag(Player $player): void{
        unset(self::$tagged[$player->getName()]);
    }

    /**
     * @param Player $player
     * @return int|null
     */
    public static function getTagTime(Player $player): ?int{
        if(self::isTagged($player)){
            return (self::$tagged[$player->getName()]["time"] + self::$tag_time) - time();
        }
        return null;
    }

    /**
     * @param Position $pos
     * @return bool
     */
    public function isInSpawn(Position $pos): bool{
        $arr = $this->getConfig()->get("spawn");
        $pos1 = explode(":", $arr["position1"]);
        $pos2 = explode(":", $arr["position2"]);
        if($pos1[3] !== $pos2[3]){
            $this->getLogger()->critical("CombatTag Postition Level does not match");
            return false;
        }

        $maxX = max($pos1[0], $pos2[0]);
        $minX = min($pos1[0], $pos2[0]);
        $maxZ = max($pos1[2], $pos2[2]);
        $minZ = min($pos1[2], $pos2[2]);

        if(
            $pos->getX() >= $minX && $pos->getX() <= $maxX &&
            $pos->getZ() >= $minZ && $pos->getZ() <= $maxZ &&
            ($pos1[3] == $pos->getLevel()->getName()) && ($pos2[3] == $pos->getLevel()->getName())
        ){
            return true;
        }
        return false;
    }

    /**
     * @param Position $pos
     * @return bool
     */
    public function isPvpSurrounding(Position $pos): bool{
        for($i = 0; $i <= 5; $i++){
            if($this->isInSpawn($pos->getSide($i))){
                return true;
            }
        }
        return false;
    }
}