<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Services\Company\CommodityListReader;
use App\Services\Company\CompanyPriceList;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Commodity List for the app — mobile's counterpart to web's own
 * `CommodityListController` (the company's default price list, kept under the
 * account that set the company up), with the same rules and the same
 * services. Only the people who price work may use it. Distinct from
 * `CommodityController`, the crew's names-and-units picker.
 *
 * Onboarding's "Save Default List" and "Skip" stay web-only.
 */
class OfficeCommodityController extends Controller
{
    use ApiResponses;

    private const SORTS = [
        'category' => 'section',
        'item_code' => 'item_code',
        'description' => 'description',
        'unit' => 'unit',
        'material_price' => 'unit_material_cost',
        'labor_hours' => 'unit_manhours',
    ];

    private const PER_PAGE = 20;

    public function __construct(
        private readonly CommodityListReader $reader,
        private readonly CompanyPriceList $list,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $search = trim((string) $request->query('search'));
        $category = (string) $request->query('category', '');
        $archived = $request->boolean('archived');
        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'category';
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $mine = fn () => PriceBookItem::query()->where('user_id', $owner);

        $items = $mine()
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->active())
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('section', 'like', "%{$search}%")
                ->orWhere('item_code', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when($category !== '', fn ($q) => $q->where('section', $category))
            ->orderBy(self::SORTS[$sort], $direction)->orderBy('description')
            ->paginate(min((int) $request->integer('per_page', self::PER_PAGE), 50));

        return $this->ok([
            'items' => $items->getCollection()->map(fn (PriceBookItem $item) => $this->present($item))->all(),
            'categories' => $mine()->active()->whereNotNull('section')->distinct()->orderBy('section')->pluck('section')->all(),
            'counts' => ['active' => $mine()->active()->count(), 'archived' => $mine()->whereNotNull('archived_at')->count()],
            'formats' => array_map(fn (string $extension) => '.'.$extension, CommodityListReader::EXTENSIONS),
            'meta' => [
                'currentPage' => $items->currentPage(),
                'lastPage' => $items->lastPage(),
                'perPage' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $item = new PriceBookItem(['user_id' => $owner, 'sample_count' => 1]);
        $this->write($owner, $item, $this->validated($request, $owner));

        return $this->created($this->present($item->refresh()), 'Item added.');
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $owner = $this->owner($request);
        $row = PriceBookItem::query()->where('user_id', $owner)->findOrFail($item);

        $this->write($owner, $row, $this->validated($request, $owner, $row));

        return $this->ok($this->present($row->refresh()), 'Item updated.');
    }

    /** Archives an item (or, with `restore`, brings it back): kept, but nothing is priced from it. */
    public function archive(Request $request, int $item): JsonResponse
    {
        $owner = $this->owner($request);
        $row = PriceBookItem::query()->where('user_id', $owner)->findOrFail($item);

        $row->forceFill(['archived_at' => $request->boolean('restore') ? null : now()])->save();

        return $this->ok(
            $this->present($row->refresh()),
            $request->boolean('restore') ? 'Item restored.' : 'Item archived. It is kept, and can be restored.',
        );
    }

    /** Reads each uploaded file, in whatever form it is, and adds what it understood to the list. */
    public function import(Request $request): JsonResponse
    {
        $owner = $this->owner($request);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:10240'],
        ], [
            'files.required' => 'Choose a file to upload.',
            'files.max' => 'Upload up to 20 files at a time.',
            'files.*.max' => 'Each file must be smaller than 10 MB.',
        ]);

        $items = [];
        $problems = [];
        $read = [];

        foreach ($request->file('files') as $index => $file) {
            $name = $file->getClientOriginalName();

            try {
                $found = $this->reader->read($file, $name);
            } catch (Throwable $e) {
                $found = [];
                if (! $e instanceof \RuntimeException) {
                    report($e);
                }
                $problems["file.{$index}"] = $e instanceof \RuntimeException
                    ? "{$name}: {$e->getMessage()}"
                    : "{$name}: this file could not be opened. Check it is not damaged or password-protected.";

                continue;
            }

            if ($found === []) {
                $problems["file.{$index}"] = "{$name}: no items found. The list needs a name for each item and a price or hours — with column headings such as Description, Unit and Price.";

                continue;
            }

            $read[] = count($found).' from '.$name;
            $items = [...$items, ...$found];
        }

        if ($items === []) {
            throw ValidationException::withMessages($problems);
        }

        $result = $this->list->upsert($owner, $items);

        return $this->ok([
            'created' => $result['created'],
            'updated' => $result['updated'],
            'problems' => array_values($problems),
        ], 'Read '.implode(', ', $read).". Added {$result['created']} new ".str('item')->plural($result['created']).($result['updated'] > 0 ? " and updated {$result['updated']}" : '').'. Check them over — you can edit or archive any item.');
    }

    /** @return array<string, mixed> */
    private function present(PriceBookItem $item): array
    {
        return [
            'id' => $item->id,
            'category' => $item->section ?? 'General',
            'itemCode' => $item->item_code,
            'description' => $item->description,
            'unit' => $item->unit,
            'materialPrice' => (float) $item->unit_material_cost,
            'laborHours' => (float) $item->unit_manhours,
            'markupPct' => $item->markup_pct === null ? null : (float) $item->markup_pct,
            'archived' => $item->archived_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $owner, ?PriceBookItem $item = null): array
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:120'],
            // An item code is one item's, within the company — archived items keep theirs.
            'item_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-\/ ]*$/',
                Rule::unique('price_book_items', 'item_code')->where('user_id', $owner)->ignore($item?->id)],
            'description' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:24'],
            'material_price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'labor_hours' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'markup_pct' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ], [
            'item_code.unique' => 'That item code is already on the list.',
            'item_code.regex' => 'Use letters, numbers, dashes or dots.',
        ]);
        $data['unit'] = mb_strtoupper(trim($data['unit']));

        return $data;
    }

    /**
     * Saves one item from the form. A hand edit pins it, so a later import of an estimating workbook
     * cannot overwrite it — web's own `write()`.
     *
     * @param  array<string, mixed>  $data
     */
    private function write(int $owner, PriceBookItem $item, array $data): void
    {
        $key = PriceBookLine::keyFor($data['description']);

        $duplicate = PriceBookItem::query()->where('user_id', $owner)->where('match_key', $key)->where('unit', $data['unit'])
            ->when($item->exists, fn ($q) => $q->whereKeyNot($item->id))->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['description' => 'That item is already on the list in that unit.']);
        }

        $archivedAt = $item->archived_at;
        $item->fill($this->list->attributes([...$data, 'markup_pct' => $data['markup_pct'] ?? null], $key, $item));
        $item->item_code = filled($data['item_code'] ?? null) ? $data['item_code'] : null;
        $item->markup_pct = $data['markup_pct'] ?? null;
        // Editing an archived item does not bring it back; restoring does.
        $item->archived_at = $archivedAt;
        $item->save();
    }

    /** Whose list this is — and only for those who price work. */
    private function owner(Request $request): int
    {
        $user = $request->user();

        abort_unless(
            $user->company_id !== null
                && ($user->isCompanyOwner() || in_array(mb_strtolower(trim((string) $user->role)), config('estimates.reviewer_roles'), true)),
            403,
            'Only the people who price work can use the commodity list.',
        );

        return (int) Ownership::bookOwnerId($user->id);
    }
}
