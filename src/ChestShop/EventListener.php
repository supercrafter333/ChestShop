<?php
declare(strict_types=1);
namespace ChestShop;

use onebone\economyapi\EconomyAPI;
use pocketmine\block\Block;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\tile\Chest;
use pocketmine\block\utils\SignText;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

class EventListener implements Listener
{
	private $plugin;
	private $databaseManager;

	public function __construct(ChestShop $plugin, DatabaseManager $databaseManager)
	{
		$this->plugin = $plugin;
		$this->databaseManager = $databaseManager;
	}

	public function onPlayerInteract(PlayerInteractEvent $event) : void
	{
		$block = $event->getBlock();
		$player = $event->getPlayer();

		switch ($block->getID()) {
			case BlockLegacyIds::SIGN_POST:
			case BlockLegacyIds::WALL_SIGN:
				if (($shopInfo = $this->databaseManager->selectByCondition([
						"signX" => $block->getPosition()->getX(),
						"signY" => $block->getPosition()->getY(),
						"signZ" => $block->getPosition()->getZ()
					])) === false) return;
				$shopInfo = $shopInfo->fetchArray(SQLITE3_ASSOC);

				if($shopInfo === false)
					return;
				if ($shopInfo['shopOwner'] === $player->getName()) {
					$player->sendMessage("Cannot purchase from your own shop!");
					return;
				}

				$event->cancel();

				$buyerMoney = EconomyAPI::getInstance()->myMoney($player->getName());
				if ($buyerMoney === false) {
					$player->sendMessage("Couldn't acquire your money data!");
					return;
				}
				if ($buyerMoney < $shopInfo['price']) {
					$player->sendMessage("Your money is not enough!");
					return;
				}

				/** @var Chest $chest */
				$chest = $player->getWorld()->getTile(new Vector3($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ']));
				$itemNum = 0;

				$searchItem = StringToItemParser::getInstance()->parse($shopInfo['productID'])->setCount((int)$shopInfo['saleNum']);
				$items = $chest->getInventory()->all($searchItem);
				foreach($items as $item) {
					$itemNum += $item->getCount();
				}
				if ($itemNum < $shopInfo['saleNum']) {
					$player->sendMessage("This shop is out of stock!");
					if (($p = $this->plugin->getServer()->getPlayerExact($shopInfo['shopOwner'])) !== null) {
						$p->sendMessage("Your ChestShop is out of stock! Replenish Item: ".$searchItem->getName());
					}
					return;
				}

				$sellerMoney = EconomyAPI::getInstance()->myMoney($shopInfo['shopOwner']);
				if(EconomyAPI::getInstance()->reduceMoney($player->getName(), $shopInfo['price'], false, "ChestShop") === EconomyAPI::RET_SUCCESS and EconomyAPI::getInstance()->addMoney($shopInfo['shopOwner'], $shopInfo['price'], false, "ChestShop") === EconomyAPI::RET_SUCCESS) {
					$chest->getInventory()->removeItem($searchItem);
					$player->getInventory()->addItem($searchItem);
					$player->sendMessage("Completed transaction");
					if (($p = $this->plugin->getServer()->getPlayerExact($shopInfo['shopOwner'])) !== null) {
						$p->sendMessage("{$player->getName()} purchased ".$searchItem->getName()." for ".EconomyAPI::getInstance()->getMonetaryUnit().$shopInfo['price']);
					}
				}else{
					EconomyAPI::getInstance()->setMoney($player->getName(), $buyerMoney);
					EconomyAPI::getInstance()->setMoney($shopInfo['shopOwner'], $sellerMoney);
					$player->sendMessage("Transaction Failed");
				}
			break;

			case BlockLegacyIds::CHEST:
				if (($shopInfo = $this->databaseManager->selectByCondition([
					"chestX" => $block->getPosition()->getX(),
					"chestY" => $block->getPosition()->getY(),
					"chestZ" => $block->getPosition()->getZ()
				])) === false) break;
				$shopInfo = $shopInfo->fetchArray(SQLITE3_ASSOC);

				if ($shopInfo !== false and $shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
					$player->sendMessage("This chest is protected! Please tap on the sign to trade.");
					$event->cancel();
				}
				break;
		}
	}

	public function onPlayerBreakBlock(BlockBreakEvent $event) : void
	{
		$block = $event->getBlock();
		$player = $event->getPlayer();

		switch ($block->getID()) {
			case BlockLegacyIds::SIGN_POST:
			case BlockLegacyIds::WALL_SIGN:
				$condition = [
					"signX" => $block->getPosition()->getX(),
					"signY" => $block->getPosition()->getY(),
					"signZ" => $block->getPosition()->getZ()
				];
				if (($shopInfo = $this->databaseManager->selectByCondition($condition)) !== false) {
					if(($shopInfo = $shopInfo->fetchArray()) === false)
						break;
					if ($shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
						$player->sendMessage("This sign is protected!");
						$event->cancel();
					} else {
						$this->databaseManager->deleteByCondition($condition);
						$player->sendMessage("Closed your ChestShop");
					}
				}
			break;

			case BlockLegacyIds::CHEST:
				$condition = [
					"chestX" => $block->getPosition()->getX(),
					"chestY" => $block->getPosition()->getY(),
					"chestZ" => $block->getPosition()->getZ()
				];
				if (($shopInfo = $this->databaseManager->selectByCondition($condition)) !== false) {
					if(($shopInfo = $shopInfo->fetchArray()) === false)
						break;
					if ($shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
						$player->sendMessage("This chest is protected!");
						$event->cancel();
					} else {
						$this->databaseManager->deleteByCondition($condition);
						$player->sendMessage("Closed your ChestShop");
					}
				}
				break;
		}
	}

	public function onSignChange(SignChangeEvent $event) : void
	{
		$signText = $event->getNewText();
		if($signText->getLine(3) === "") return;

		$productAmount = (int) $signText->getLine(1);
		$price = (int) $signText->getLine(2);
		$itemId = $signText->getLine(3);
		$this->isItem($itemId) ?: $itemId = null;

		$sign = $event->getBlock()->getPosition();

		// Check sign format...
		if ($signText->getLine(0) !== "") return;
		if ($productAmount <= 0) return;
		if ($price < 0) return;
		if ($itemId === null) return;
		if (($chest = $this->getSideChest($sign)?->getPosition()) === null) return;

		$shopOwner = $event->getPlayer()->getName();

		$shopDataByOwner = $this->databaseManager->selectByCondition(["shopOwner" => "'$shopOwner'"]);
		$counter = 0;
		while ($res = $shopDataByOwner->fetchArray(SQLITE3_ASSOC)) {
			++$counter;
			if($res["signX"] === $sign->getX() and $res["signY"] === $sign->getY() and $res["signZ"] === $sign->getZ()) { // tests if we are editing an existing shop
				if(($productName = (StringToItemParser::getInstance()->parse($itemId))?->getName()) === null)
					throw new AssumptionFailedError("Item does not exist");
				$event->setNewText(new SignText([
					$shopOwner,
					"Amount: $productAmount",
					"Price: ".EconomyAPI::getInstance()->getMonetaryUnit().$price,
					$productName
				]));
				$this->databaseManager->registerShop($shopOwner, $productAmount, $price, $itemId, $sign, $chest);
				return;
			}
		}

		if($counter >= $this->plugin->getMaxPlayerShops($event->getPlayer()) and !$event->getPlayer()->hasPermission("chestshop.admin") and !$event->getPlayer()->hasPermission("chestshop.makeshop.unlimited")) {
			$event->getPlayer()->sendMessage(TextFormat::RED."You don't have permission to make more shops");
			return;
		}

		$productName = StringToItemParser::getInstance()->parse($itemId)->getName();
		$event->setNewText(new SignText([
			$shopOwner,
			"Amount: $productAmount",
			"Price: ".EconomyAPI::getInstance()->getMonetaryUnit().$price,
			$productName
		]));

		$this->databaseManager->registerShop($shopOwner, $productAmount, $price, $itemId, $sign, $chest);
	}

	private function getSideChest(Position $pos) : ?Block
	{
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ()));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ()));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX(), $pos->getY() - 1, $pos->getZ()));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		$block = $pos->getWorld()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1));
		if ($block->getID() === BlockLegacyIds::CHEST) return $block;
		return null;
	}

	private function isItem(string &$id) : bool
	{
		return ($id = StringToItemParser::getInstance()->parse($id)?->getVanillaName()) !== null;
	}
} 
