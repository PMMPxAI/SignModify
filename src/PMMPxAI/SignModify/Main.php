<?php

declare(strict_types=1);

namespace PMMPxAI\SignModify;

use pocketmine\block\BaseSign;
use pocketmine\block\FloorSign;
use pocketmine\block\WallSign;
use pocketmine\block\tile\Sign as TileSign;
use pocketmine\block\utils\SignText;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\event\world\ChunkUnloadEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\math\Facing;
use pocketmine\math\Vector3 as PMVector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\OpenSignPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Binary;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\World;
use function abs;
use function cos;
use function deg2rad;
use function fmod;
use function implode;
use function rtrim;
use function sin;

final class Main extends PluginBase implements Listener{
    private array $session = [];
    private array $sentChunks = [];
    private BackSignStore $store;

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->store = new BackSignStore($this);
    }

    protected function onDisable() : void{
        $this->store->close();
        $this->sentChunks = [];
        $this->session = [];
    }

    public function onInteract(PlayerInteractEvent $event) : void{
        if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            return;
        }
        $block = $event->getBlock();
        if(!$block instanceof BaseSign){
            return;
        }
        $player = $event->getPlayer();
        $pos = $block->getPosition();
        $tile = $pos->getWorld()->getTile($pos);
        if(!$tile instanceof TileSign){
            return;
        }
        $front = !$this->isFrontInteraction($block, $player->getLocation()->getYaw(), $event->getFace(), $player->getPosition(), $pos->add(0.5, 0.5, 0.5));
        $block->setEditorEntityRuntimeId($player->getId());
        $pos->getWorld()->setBlock($pos, $block);
        $event->cancel();
        $packet = OpenSignPacket::create(new BlockPosition($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()), $front);
        $player->getNetworkSession()->sendDataPacket($packet);
        $this->session[$player->getUniqueId()->getBytes()] = [
            'x' => $pos->getFloorX(),
            'y' => $pos->getFloorY(),
            'z' => $pos->getFloorZ(),
            'world' => $pos->getWorld()->getFolderName(),
            'front' => $front
        ];
    }

    public function onSignChange(SignChangeEvent $event) : void{
        $player = $event->getPlayer();
        $key = $player->getUniqueId()->getBytes();
        if(!isset($this->session[$key])){
            return;
        }
        $data = $this->session[$key];
        $signBlock = $event->getSign();
        $pos = $signBlock->getPosition();
        if($pos->getFloorX() !== $data['x'] || $pos->getFloorY() !== $data['y'] || $pos->getFloorZ() !== $data['z'] || $pos->getWorld()->getFolderName() !== $data['world']){
            unset($this->session[$key]);
            return;
        }
        if($data['front']){
            unset($this->session[$key]);
            $this->scheduleReapplyBack($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
            return;
        }
        $event->cancel();
        $world = $pos->getWorld();
        $tile = $world->getTile($pos);
        if(!$tile instanceof TileSign){
            unset($this->session[$key]);
            return;
        }
        $frontText = $tile->getText();
        $backText = $event->getNewText();
        $this->store->save($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $backText->getLines());
        $this->sendFrontBack($world, $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $tile, $frontText, $backText);
        $tile->setEditorEntityRuntimeId(null);
        unset($this->session[$key]);
    }

    public function onChunkLoad(ChunkLoadEvent $event) : void{
        $world = $event->getWorld();
        $cx = $event->getChunkX();
        $cz = $event->getChunkZ();
        
    }

    public function onChunkUnload(ChunkUnloadEvent $event) : void{
        $world = $event->getWorld();
        $cx = $event->getChunkX();
        $cz = $event->getChunkZ();
        $this->store->clearChunk($world->getFolderName(), $cx, $cz);
    }

    public function onPlayerJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $this->sentChunks[$player->getUniqueId()->getBytes()] = [];
        $pos = $player->getPosition();
        $this->sendChunkBacksToPlayer($player, $pos->getWorld(), $pos->getFloorX() >> 4, $pos->getFloorZ() >> 4);
    }

    public function onPlayerQuit(PlayerQuitEvent $event) : void{
        unset($this->sentChunks[$event->getPlayer()->getUniqueId()->getBytes()]);
        unset($this->session[$event->getPlayer()->getUniqueId()->getBytes()]);
    }

    public function onPlayerMove(PlayerMoveEvent $event) : void{
        $from = $event->getFrom();
        $to = $event->getTo();
        if($from->getWorld() !== $to->getWorld()){
            $this->sentChunks[$event->getPlayer()->getUniqueId()->getBytes()] = [];
        }
        $fcx = $from->getFloorX() >> 4;
        $fcz = $from->getFloorZ() >> 4;
        $tcx = $to->getFloorX() >> 4;
        $tcz = $to->getFloorZ() >> 4;
        if($fcx === $tcx && $fcz === $tcz){
            return;
        }
        $this->sendChunkBacksToPlayer($event->getPlayer(), $to->getWorld(), $tcx, $tcz);
    }

    public function onBlockBreak(BlockBreakEvent $event) : void{
        $block = $event->getBlock();
        if(!$block instanceof BaseSign){
            return;
        }
        $pos = $block->getPosition();
        $this->store->delete($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
        $cx = $pos->getFloorX() >> 4;
        $cz = $pos->getFloorZ() >> 4;
        $worldKey = $pos->getWorld()->getFolderName();
        $ck = "$cx:$cz";
        if(isset($this->chunkCache[$worldKey][$ck])){
            foreach($this->chunkCache[$worldKey][$ck] as $k => $row){
                if($row['x'] === $pos->getFloorX() && $row['y'] === $pos->getFloorY() && $row['z'] === $pos->getFloorZ()){
                    unset($this->chunkCache[$worldKey][$ck][$k]);
                }
            }
        }
    }

    private function isFrontInteraction(BaseSign $sign, float $playerYaw, int $clickedFace, PMVector3 $playerPos, PMVector3 $signCenter) : bool{
        if($sign instanceof WallSign){
            $f = $sign->getFacing();
            if($clickedFace === $f){
                return true;
            }
            if($clickedFace === Facing::opposite($f)){
                return false;
            }
            $yaw = $this->yawFromHorizontalFacing($f);
            $fx = -sin(deg2rad($yaw));
            $fz = cos(deg2rad($yaw));
            return $this->isFrontByDot($fx, $fz, $playerPos, $signCenter);
        }
        if($sign instanceof FloorSign){
            $rot = $sign->getRotation();
            $yaw = ($rot * 22.5) - 180.0;
            $fx = -sin(deg2rad($yaw));
            $fz = cos(deg2rad($yaw));
            return $this->isFrontByDot($fx, $fz, $playerPos, $signCenter);
        }
        $dyaw = $this->yawDelta($playerYaw, 0.0);
        return abs($dyaw) <= 90.0;
    }

    private function isFrontByDot(float $fx, float $fz, PMVector3 $playerPos, PMVector3 $signCenter) : bool{
        $dx = $playerPos->x - $signCenter->x;
        $dz = $playerPos->z - $signCenter->z;
        $dot = ($fx * $dx) + ($fz * $dz);
        return $dot >= 0;
    }

    private function yawDelta(float $a, float $b) : float{
        $d = fmod(($a - $b + 540.0), 360.0) - 180.0;
        return $d;
    }

    private function yawFromHorizontalFacing(int $f) : float{
        if($f === Facing::NORTH){
            return 180.0;
        }
        if($f === Facing::SOUTH){
            return 0.0;
        }
        if($f === Facing::WEST){
            return 90.0;
        }
        return -90.0;
    }




    private function sendChunkBacksToPlayer(\pocketmine\player\Player $player, World $world, int $cx, int $cz) : void{
        $wk = $world->getFolderName();
        $key = $player->getUniqueId()->getBytes();
        $ck = "$cx:$cz";
        if(isset($this->sentChunks[$key][$wk][$ck])){
            return;
        }
        $list = $this->store->getChunk($wk, $cx, $cz);
        if($list === []){
            $this->sentChunks[$key][$wk][$ck] = true;
            return;
        }
        foreach($list as $row){
            $x = $row['x'];
            $y = $row['y'];
            $z = $row['z'];
            $pos = new PMVector3($x, $y, $z);
            $tile = $world->getTile($pos);
            if(!$tile instanceof TileSign){
                continue;
            }
            $frontText = $tile->getText();
            $backLines = explode("\n", (string)$row['text'], 5);
            $backLines = array_slice($backLines, 0, 4);
            while(count($backLines) < 4){
                $backLines[] = "";
            }
            $backText = new SignText($backLines, $frontText->getBaseColor(), $frontText->isGlowing());
            $this->sendFrontBack($world, $x, $y, $z, $tile, $frontText, $backText, $player);
        }
        $this->sentChunks[$key][$wk][$ck] = true;
    }

    

    private function scheduleReapplyBack(World $world, int $x, int $y, int $z) : void{
        $lines = $this->store->getLines($world, $x, $y, $z);
        if($lines === null){
            return;
        }
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($world, $x, $y, $z, $lines) : void{
            $pos = new PMVector3($x, $y, $z);
            $tile = $world->getTile($pos);
            if(!$tile instanceof TileSign){
                return;
            }
            $frontText = $tile->getText();
            $backText = new SignText($lines, $frontText->getBaseColor(), $frontText->isGlowing());
            $this->sendFrontBack($world, $x, $y, $z, $tile, $frontText, $backText);
        }), 1);
    }

    

    public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
        $packet = $event->getPacket();
        if(!$packet instanceof BlockActorDataPacket){
            return;
        }
        $player = $event->getOrigin()->getPlayer();
        if($player === null){
            return;
        }
        $key = $player->getUniqueId()->getBytes();
        if(!isset($this->session[$key])){
            return;
        }
        $edit = $this->session[$key];
        if($edit['front']){
            return;
        }
        $bp = $packet->blockPosition;
        $bx = $bp->getX();
        $by = $bp->getY();
        $bz = $bp->getZ();
        if($bx !== $edit['x'] || $by !== $edit['y'] || $bz !== $edit['z']){
            return;
        }
        $nbt = $packet->nbt->getRoot();
        if(!$nbt instanceof CompoundTag){
            return;
        }
        $back = $nbt->getTag(TileSign::TAG_BACK_TEXT);
        if(!$back instanceof CompoundTag){
            return;
        }
        $blob = (string) $back->getString(TileSign::TAG_TEXT_BLOB, "");
        $lines = explode("\n", $blob, 5);
        $lines = array_slice($lines, 0, 4);
        while(count($lines) < 4){
            $lines[] = "";
        }
        $world = $player->getWorld();
        $this->store->save($world, $bx, $by, $bz, $lines);
        $tile = $world->getTile(new PMVector3($bx, $by, $bz));
        if(!$tile instanceof TileSign){
            unset($this->session[$key]);
            return;
        }
        $frontText = $tile->getText();
        $backText = new SignText($lines, $frontText->getBaseColor(), $frontText->isGlowing());
        $this->sendFrontBack($world, $bx, $by, $bz, $tile, $frontText, $backText);
        unset($this->session[$key]);
        $event->cancel();
    }

    private function sideNbt(SignText $text, int $argb, bool $glowing) : CompoundTag{
        return CompoundTag::create()
            ->setString(TileSign::TAG_TEXT_BLOB, rtrim(implode("\n", $text->getLines()), "\n"))
            ->setInt(TileSign::TAG_TEXT_COLOR, Binary::signInt($argb))
            ->setByte(TileSign::TAG_GLOWING_TEXT, $glowing ? 1 : 0)
            ->setByte(TileSign::TAG_PERSIST_FORMATTING, 1);
    }

    private function sendFrontBack(World $world, int $x, int $y, int $z, TileSign $tile, SignText $front, SignText $back, ?\pocketmine\player\Player $only = null) : void{
        $argb = $front->getBaseColor()->toARGB();
        $frontNbt = $this->sideNbt($front, $argb, $front->isGlowing());
        $backNbt = $this->sideNbt($back, $argb, $front->isGlowing());
        $waxedByte = method_exists($tile, 'isWaxed') && $tile->isWaxed() ? 1 : 0;
        $root = CompoundTag::create()
            ->setString(TileSign::TAG_ID, "Sign")
            ->setInt(TileSign::TAG_X, $x)
            ->setInt(TileSign::TAG_Y, $y)
            ->setInt(TileSign::TAG_Z, $z)
            ->setTag(TileSign::TAG_FRONT_TEXT, $frontNbt)
            ->setTag(TileSign::TAG_BACK_TEXT, $backNbt)
            ->setByte(TileSign::TAG_WAXED, $waxedByte)
            ->setLong(TileSign::TAG_LOCKED_FOR_EDITING_BY, -1);
        $pkt = BlockActorDataPacket::create(new BlockPosition($x, $y, $z), new CacheableNbt($root));
        if($only !== null){
            $only->getNetworkSession()->sendDataPacket($pkt);
            return;
        }
        foreach($world->getPlayers() as $p){
            $p->getNetworkSession()->sendDataPacket($pkt);
        }
    }
}
