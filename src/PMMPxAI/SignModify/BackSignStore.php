<?php

declare(strict_types=1);

namespace PMMPxAI\SignModify;

use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use function array_values;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function json_decode;
use function json_encode;
use function rtrim;

final class BackSignStore{
    private PluginBase $plugin;
    private bool $useSqlite = false;
    private $db = null;
    private $stmtInsert = null;
    private $stmtDelete = null;
    private $stmtSelectChunk = null;
    private array $cache = [];

    public function __construct(PluginBase $plugin){
        $this->plugin = $plugin;
        @mkdir($plugin->getDataFolder());
        if(class_exists('SQLite3')){
            $path = $plugin->getDataFolder() . 'back_signs.sqlite';
            $this->db = new \SQLite3($path);
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA synchronous=NORMAL');
            $this->db->exec('CREATE TABLE IF NOT EXISTS back_signs (world TEXT NOT NULL, x INTEGER NOT NULL, y INTEGER NOT NULL, z INTEGER NOT NULL, text TEXT NOT NULL, color INTEGER NOT NULL, glowing INTEGER NOT NULL, cx INTEGER NOT NULL, cz INTEGER NOT NULL, PRIMARY KEY(world,x,y,z))');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_chunk ON back_signs(world,cx,cz)');
            $this->stmtInsert = $this->db->prepare('INSERT OR REPLACE INTO back_signs(world,x,y,z,text,color,glowing,cx,cz) VALUES (?,?,?,?,?,?,?,?,?)');
            $this->stmtDelete = $this->db->prepare('DELETE FROM back_signs WHERE world=? AND x=? AND y=? AND z=?');
            $this->stmtSelectChunk = $this->db->prepare('SELECT x,y,z,text,color,glowing FROM back_signs WHERE world=? AND cx=? AND cz=?');
            $this->useSqlite = true;
        }else{
            @mkdir($plugin->getDataFolder() . 'chunks', 0777, true);
        }
    }

    public function close() : void{
        if($this->stmtInsert !== null){ $this->stmtInsert->close(); }
        if($this->stmtDelete !== null){ $this->stmtDelete->close(); }
        if($this->stmtSelectChunk !== null){ $this->stmtSelectChunk->close(); }
        if($this->db !== null && $this->useSqlite){ $this->db->close(); }
        $this->stmtInsert = null;
        $this->stmtDelete = null;
        $this->stmtSelectChunk = null;
        $this->db = null;
        $this->cache = [];
    }

    public function save(World $world, int $x, int $y, int $z, array $lines) : void{
        $wk = $world->getFolderName();
        $cx = $x >> 4;
        $cz = $z >> 4;
        $blob = rtrim(implode("\n", $lines), "\n");
        if($this->useSqlite){
            $this->stmtInsert->reset();
            $this->stmtInsert->bindValue(1, $wk, \SQLITE3_TEXT);
            $this->stmtInsert->bindValue(2, $x, \SQLITE3_INTEGER);
            $this->stmtInsert->bindValue(3, $y, \SQLITE3_INTEGER);
            $this->stmtInsert->bindValue(4, $z, \SQLITE3_INTEGER);
            $this->stmtInsert->bindValue(5, $blob, \SQLITE3_TEXT);
            $this->stmtInsert->bindValue(6, 0xff000000, \SQLITE3_INTEGER);
            $this->stmtInsert->bindValue(7, 0, \SQLITE3_INTEGER);
            $this->stmtInsert->bindValue(8, $cx, \SQLITE3_INTEGER);
            $this->stmtInsert->bindValue(9, $cz, \SQLITE3_INTEGER);
            $this->stmtInsert->execute();
        }else{
            $file = $this->jsonFile($wk, $cx, $cz);
            $data = [];
            if(is_file($file)){
                $raw = file_get_contents($file);
                if($raw !== false && $raw !== ''){
                    $data = json_decode($raw, true) ?: [];
                }
            }
            $data[$this->posKey($x, $y, $z)] = [
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'text' => $blob,
                'color' => 0xff000000,
                'glowing' => 0
            ];
            $tmp = $file . '.tmp';
            file_put_contents($tmp, json_encode($data));
            @rename($tmp, $file);
        }
        $ck = $this->chunkKey($cx, $cz);
        $this->ensureLoaded($wk, $cx, $cz);
        $this->cache[$wk][$ck][$this->posKey($x,$y,$z)] = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'text' => $blob,
            'color' => 0xff000000,
            'glowing' => 0
        ];
    }

    public function delete(World $world, int $x, int $y, int $z) : void{
        $wk = $world->getFolderName();
        $cx = $x >> 4;
        $cz = $z >> 4;
        if($this->useSqlite){
            $this->stmtDelete->reset();
            $this->stmtDelete->bindValue(1, $wk, \SQLITE3_TEXT);
            $this->stmtDelete->bindValue(2, $x, \SQLITE3_INTEGER);
            $this->stmtDelete->bindValue(3, $y, \SQLITE3_INTEGER);
            $this->stmtDelete->bindValue(4, $z, \SQLITE3_INTEGER);
            $this->stmtDelete->execute();
        }else{
            $file = $this->jsonFile($wk, $cx, $cz);
            if(is_file($file)){
                $raw = file_get_contents($file);
                $data = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
                $key = $this->posKey($x, $y, $z);
                if(isset($data[$key])){
                    unset($data[$key]);
                    $tmp = $file . '.tmp';
                    file_put_contents($tmp, json_encode($data));
                    @rename($tmp, $file);
                }
            }
        }
        $ck = $this->chunkKey($cx, $cz);
        if(isset($this->cache[$wk][$ck][$this->posKey($x,$y,$z)])){
            unset($this->cache[$wk][$ck][$this->posKey($x,$y,$z)]);
        }
    }

    public function getChunk(string $worldFolder, int $cx, int $cz) : array{
        $this->ensureLoaded($worldFolder, $cx, $cz);
        $ck = $this->chunkKey($cx, $cz);
        return array_values($this->cache[$worldFolder][$ck]);
    }

    public function clearChunk(string $worldFolder, int $cx, int $cz) : void{
        unset($this->cache[$worldFolder][$this->chunkKey($cx,$cz)]);
    }

    public function getLines(World $world, int $x, int $y, int $z) : ?array{
        $wk = $world->getFolderName();
        $cx = $x >> 4;
        $cz = $z >> 4;
        $this->ensureLoaded($wk, $cx, $cz);
        $row = $this->cache[$wk][$this->chunkKey($cx,$cz)][$this->posKey($x,$y,$z)] ?? null;
        if($row === null){
            return null;
        }
        $lines = explode("\n", (string)$row['text'], 5);
        $lines = array_slice($lines, 0, 4);
        while(count($lines) < 4){ $lines[] = ""; }
        return $lines;
    }

    private function ensureLoaded(string $worldFolder, int $cx, int $cz) : void{
        $ck = $this->chunkKey($cx, $cz);
        if(isset($this->cache[$worldFolder][$ck])){
            return;
        }
        $this->cache[$worldFolder][$ck] = [];
        if($this->useSqlite){
            $this->stmtSelectChunk->reset();
            $this->stmtSelectChunk->bindValue(1, $worldFolder, \SQLITE3_TEXT);
            $this->stmtSelectChunk->bindValue(2, $cx, \SQLITE3_INTEGER);
            $this->stmtSelectChunk->bindValue(3, $cz, \SQLITE3_INTEGER);
            $res = $this->stmtSelectChunk->execute();
            if($res !== false){
                while($row = $res->fetchArray(\SQLITE3_ASSOC)){
                    $key = $this->posKey((int)$row['x'], (int)$row['y'], (int)$row['z']);
                    $this->cache[$worldFolder][$ck][$key] = [
                        'x' => (int)$row['x'],
                        'y' => (int)$row['y'],
                        'z' => (int)$row['z'],
                        'text' => (string)$row['text'],
                        'color' => (int)$row['color'],
                        'glowing' => (int)$row['glowing']
                    ];
                }
                $res->finalize();
            }
            return;
        }
        $file = $this->jsonFile($worldFolder, $cx, $cz);
        if(is_file($file)){
            $raw = file_get_contents($file);
            $data = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
            foreach($data as $row){
                $key = $this->posKey((int)$row['x'], (int)$row['y'], (int)$row['z']);
                $this->cache[$worldFolder][$ck][$key] = [
                    'x' => (int)$row['x'],
                    'y' => (int)$row['y'],
                    'z' => (int)$row['z'],
                    'text' => (string)$row['text'],
                    'color' => (int)($row['color'] ?? 0xff000000),
                    'glowing' => (int)($row['glowing'] ?? 0)
                ];
            }
        }
    }

    private function jsonFile(string $worldFolder, int $cx, int $cz) : string{
        $dir = $this->plugin->getDataFolder() . 'chunks/' . $worldFolder;
        @mkdir($dir, 0777, true);
        return $dir . '/' . $this->chunkKey($cx,$cz) . '.json';
    }

    private function chunkKey(int $cx, int $cz) : string{ return $cx . '_' . $cz; }
    private function posKey(int $x, int $y, int $z) : string{ return $x . ':' . $y . ':' . $z; }
}

