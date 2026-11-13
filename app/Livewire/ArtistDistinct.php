<?php

namespace App\Livewire;

use App\Livewire\Concerns\ShowsMessages;
use App\Models\Artist;
use App\Support\ArtistRegistry;
use App\Support\ArtistTools;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Interpreten › Verschieden: als „nicht derselbe“ markierte Paare – aufheben, damit sie wieder als ähnlich kommen. */
class ArtistDistinct extends Component
{
    use ShowsMessages;

    private const STEP = 100;

    public string $query = '';

    public int $limit = self::STEP;

    public function updatedQuery(): void
    {
        $this->limit = self::STEP;
    }

    public function undistinct(int $a, int $b, ArtistRegistry $registry): void
    {
        $registry->undistinct($a, $b);
        $names = Artist::whereIn('id', [$a, $b])->pluck('name', 'id');
        $this->say('„'.($names[$a] ?? '?').'“ und „'.($names[$b] ?? '?').'“ können wieder als ähnlich vorgeschlagen werden.');
        $this->dispatch('artist-tools-changed');
    }

    public function more(): void
    {
        $this->limit += self::STEP;
    }

    public function render()
    {
        $like = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($this->query))).'%';
        $query = DB::table('artist_distinct')->join('artists as a', 'a.id', '=', 'artist_distinct.artist_id')->join('artists as b', 'b.id', '=', 'artist_distinct.other_id')
            ->when(trim($this->query) !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(a.name) LIKE ?', [$like])->orWhereRaw('LOWER(b.name) LIKE ?', [$like])));
        $total = (clone $query)->count();
        $pairs = $query->orderByDesc('artist_distinct.created_at')->orderBy('a.name')->limit($this->limit)
            ->get(['a.id as a_id', 'a.name as a_name', 'b.id as b_id', 'b.name as b_name', 'artist_distinct.created_at']);

        return view('livewire.artist-distinct', [
            'all' => DB::table('artist_distinct')->count(),
            'total' => $total,
            'pairs' => $pairs,
            'stats' => ArtistTools::stats($pairs->pluck('a_id')->merge($pairs->pluck('b_id'))->unique()->all()),
        ]);
    }
}
