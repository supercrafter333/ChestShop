<?php
declare(strict_types=1);
namespace ChestShop;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

class ChestShop extends PluginBase
{
	private DatabaseManager $db;

	public function onEnable() : void
	{
		$this->db = new DatabaseManager($this, 256);
		$this->db->tryUpgradeDB();
		$this->getServer()->getPluginManager()->registerEvents(new EventListener($this, $this->db), $this);
	}

	public function onDisable() : void
	{
		$this->db->close();
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
	{
		if(!isset($args[0]))
			return false;

		if(StringToItemParser::getInstance()->parse($args[0]) !== null) {
			$sender->sendMessage("ID: $args[0]");
			return true;
		}

		$highest = "";
		$highValue = 0;
		foreach(StringToItemParser::getInstance()->getKnownAliases() as $alias) {
			similar_text($alias, $args[0], $percent);
			if($percent > $highValue) {
				$highValue = $percent;
				$highest = $alias;
				if($percent === 100)
					break;
			}
		}
		$sender->sendMessage("ID: ".substr($highest, strlen('minecraft:')));
		return true;
	}

	/**
	 * Get the maximum number of shops a player can create
	 *
	 * @param Player $player
	 *
	 * @return int
	 */
	public function getMaxPlayerShops(Player $player) : int {
		if($player->hasPermission("chestshop.makeshop.unlimited"))
			return PHP_INT_MAX;
		$player->recalculatePermissions();
		$perms = $player->getEffectivePermissions();
		$perms = array_filter($perms, function(string $name) : bool {
			return str_starts_with($name, "chestshop.makeshop.") and !str_contains($name, "unlimited");
		}, ARRAY_FILTER_USE_KEY);
		if(count($perms) === 0)
			return 0;
		krsort($perms, SORT_FLAG_CASE | SORT_NATURAL);
		foreach($perms as $name => $perm) {
			$maxPlots = substr($name, 19);
			if(is_numeric($maxPlots)) {
				return (int) $maxPlots;
			}
		}
		return 0;
	}
}