<?php
declare(strict_types=1);
return '<?php
namespace Example\\SelectedChunkWarnings0;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            needInteger("bad");$cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
function needInteger(int $value):void {}

namespace Example\\SelectedChunkWarnings1;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection &$batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings2;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as &$item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings3;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { unknown($batch);$ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings4;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);$batch=[];
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings5;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);$copy=$batch;
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings6;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection &$batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings7;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);$batch->push(new Parcel);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings8;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $this->replace($batch);$cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings9;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$record=new Parcel;$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings10;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):object { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings11;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(\\Illuminate\\Support\\Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings12;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$callback=$record->adjust(...);$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings13;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            /** @var Collection<int,Parcel> $batch */ $cache=$this->readBatch($batch);
            foreach($batch as $record){$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
namespace Example\\SelectedChunkWarnings14;
use Illuminate\\Database\\Eloquent\\Collection;
class Parcel extends \\Illuminate\\Database\\Eloquent\\Model { public function adjust():void {} }
class Owner {
    /** @param Collection<int,Parcel> $batch */
    protected function readBatch(Collection $batch):array { $ids=[];foreach($batch as $item){$ids[]=$item->id;}return $ids; }
    public function process():void {
        $query=Parcel::query();$query->where(\'enabled\',1);$query->with([\'owner\']);$total=(clone $query)->count();
        $query->with([\'owner\'])->chunkById(3,function(Collection $batch):void {
            $cache=$this->readBatch($batch);
            foreach($batch as $record){unknown($record);$old=$record->id;$record->adjust();$record->saveQuietly();}
        });
    }
}
';
