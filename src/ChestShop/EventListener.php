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
use pocketmine\item\ItemFactory;
use pocketmine\math\Vector3;
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
				}else{
					$event->cancel();
				}
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
				$pID = $shopInfo['productID'];
				$pMeta = $shopInfo['productMeta'];
				for ($i = 0; $i < $chest->getInventory()->getSize(); $i++) {
					$item = $chest->getInventory()->getItem($i);
					// use getDamage() method to get metadata of item
					if ($item->getID() === $pID and $item->getMeta() === $pMeta) $itemNum += $item->getCount();
				}
				if ($itemNum < $shopInfo['saleNum']) {
					$player->sendMessage("This shop is out of stock!");
					if (($p = $this->plugin->getServer()->getPlayerExact($shopInfo['shopOwner'])) !== null) {
						$p->sendMessage("Your ChestShop is out of stock! Replenish Item: ".ItemFactory::get($pID, $pMeta)->getName());
					}
					return;
				}

				$item = ItemFactory::get((int)$shopInfo['productID'], (int)$shopInfo['productMeta'], (int)$shopInfo['saleNum']);
				$chest->getInventory()->removeItem($item);
				$player->getInventory()->addItem($item);
				$sellerMoney = EconomyAPI::getInstance()->myMoney($shopInfo['shopOwner']);
				if(EconomyAPI::getInstance()->reduceMoney($player->getName(), $shopInfo['price'], false, "ChestShop") === EconomyAPI::RET_SUCCESS and EconomyAPI::getInstance()->addMoney($shopInfo['shopOwner'], $shopInfo['price'], false, "ChestShop") === EconomyAPI::RET_SUCCESS) {
					$player->sendMessage("Completed transaction");
					if (($p = $this->plugin->getServer()->getPlayerExact($shopInfo['shopOwner'])) !== null) {
						$p->sendMessage("{$player->getName()} purchased ".ItemFactory::get($pID, $pMeta)->getName()." for ".EconomyAPI::getInstance()->getMonetaryUnit().$shopInfo['price']);
					}
				}else{
					$player->getInventory()->removeItem($item);
					$chest->getInventory()->addItem($item);
					EconomyAPI::getInstance()->setMoney($player->getName(), $buyerMoney);
					EconomyAPI::getInstance()->setMoney($shopInfo['shopOwner'], $sellerMoney);
					$player->sendMessage("Transaction Failed");
				}
				break;

			case BlockLegacyIds::CHEST:
				$shopInfo = $this->databaseManager->selectByCondition([
					"chestX" => $block->getPosition()->getX(),
					"chestY" => $block->getPosition()->getY(),
					"chestZ" => $block->getPosition()->getZ()
				]);
				if($shopInfo === false)
					break;
				$shopInfo = $shopInfo->fetchArray(SQLITE3_ASSOC);
				if ($shopInfo !== false and $shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
					$player->sendMessage("This chest has been protected!");
					$event->cancel();
				}
				break;

			default:
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
				$shopInfo = $this->databaseManager->selectByCondition($condition);
				if ($shopInfo !== false) {
					$shopInfo = $shopInfo->fetchArray();
					if($shopInfo === false)
						break;
					if ($shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
						$player->sendMessage("This sign has been protected!");
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
				$shopInfo = $this->databaseManager->selectByCondition($condition);
				if ($shopInfo !== false) {
					$shopInfo = $shopInfo->fetchArray();
					if($shopInfo === false)
						break;
					if ($shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.admin")) {
						$player->sendMessage("This chest has been protected!");
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
		$shopOwner = $event->getPlayer()->getName();
		$signText = $event->getNewText();
		$saleNum = $signText->getLine(1);
		$price = $signText->getLine(2);
		$productData = explode(":", $signText->getLine(3));
		/** @var int|bool $pID */
		$pID = $this->isItem($id = (int) array_shift($productData)) ? $id : false;
		$pMeta = ($meta = array_shift($productData)) ? (int)$meta : 0;

		$sign = $event->getBlock();

		// Check sign format...
		if ($signText->getLine(0) !== "") return;
		if (!is_numeric($saleNum) or $saleNum <= 0) return;
		if (!is_numeric($price) or $price < 0) return;
		if ($pID === false) return;
		if (($chest = $this->getSideChest($sign->getPosition())) === false) return;
		$shops = $this->databaseManager->selectByCondition(["shopOwner" => "'$shopOwner'"]);
		$res = true;
		$count = [];
		while ($res !== false) {
			$res = $shops->fetchArray(SQLITE3_ASSOC);
			if($res !== false) {
				$count[] = $res;
				if($res["signX"] === $event->getBlock()->getPosition()->getX() and $res["signY"] === $event->getBlock()->getPosition()->getY() and $res["signZ"] === $event->getBlock()->getPosition()->getZ()) {
					$productName = ItemFactory::get($pID, $pMeta)->getName();
					$event->setNewText(new SignText([
						$shopOwner,
						"Amount: $saleNum",
						"Price: ".EconomyAPI::getInstance()->getMonetaryUnit().$price,
						$productName
					]));

					$this->databaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest);
					return;
				}
			}
		}
		if(empty($signText->getLine(3))) return;
		if(count($count) >= $this->plugin->getMaxPlayerShops($event->getPlayer()) and !$event->getPlayer()->hasPermission("chestshop.admin")) {
			$event->getPlayer()->sendMessage(TextFormat::RED."You don't have permission to make more shops");
			return;
		}

		$productName = ItemFactory::get($pID, $pMeta)->getName();
		$event->setNewText(new SignText([
			$shopOwner,
			"Amount: $saleNum",
			"Price: ".EconomyAPI::getInstance()->getMonetaryUnit().$price,
			$productName
		]));

		$this->databaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest);
	}

	private function getSideChest(Position $pos) : Block|bool
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
		return false;
	}

	private function isItem(int $id) : bool
	{
		return ItemFactory::getInstance()->isRegistered($id);
	}
} 
