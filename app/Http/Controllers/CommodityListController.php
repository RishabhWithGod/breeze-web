<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Services\Company\CommodityListReader;
use App\Services\Company\CompanyPriceList;
use App\Services\Onboarding\SetupChecklist;
use App\Support\Ownership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Commodity List Setup: the company's default price list.
 *
 * It is the company's price book under another name — the same items, kept under the account
 * that set the company up, so every manager prices from one list. A project that has no rate
 * list of its own is priced from it. Lists can be uploaded in whatever form a company has them
 * (see {@see CommodityListReader}) or built by hand. Nothing here is required during onboarding.
 */
class CommodityListController extends Controller
{
    /** Sortable columns, by the column each one is stored in. */
    private const SORTS = [
        'category' => 'section',
        'item_code' => 'item_code',
        'description' => 'description',
        'unit' => 'unit',
        'material_price' => 'unit_material_cost',
        'labor_hours' => 'unit_manhours',
    ];

    private const PER_PAGE = 12;

    public function __construct(
        private readonly CommodityListReader $reader,
        private readonly CompanyPriceList $list,
        private readonly SetupChecklist $checklist,
    ) {}

    public function index(Request $request): Response
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
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('CommodityList', [
            'items' => $items->getCollection()->map(fn (PriceBookItem $item) => [
                'id' => $item->id,
                'category' => $item->section ?? 'General',
                'itemCode' => $item->item_code,
                'description' => $item->description,
                'unit' => $item->unit,
                'materialPrice' => (float) $item->unit_material_cost,
                'laborHours' => (float) $item->unit_manhours,
                'markupPct' => $item->markup_pct === null ? null : (float) $item->markup_pct,
                'archived' => $item->archived_at !== null,
            ])->all(),
            'page' => ['current' => $items->currentPage(), 'last' => $items->lastPage(), 'total' => $items->total(), 'from' => $items->firstItem() ?? 0, 'to' => $items->lastItem() ?? 0],
            'filters' => ['search' => $search, 'category' => $category, 'archived' => $archived, 'sort' => $sort, 'dir' => $direction],
            'categories' => $mine()->active()->whereNotNull('section')->distinct()->orderBy('section')->pluck('section')->all(),
            'counts' => ['active' => $mine()->active()->count(), 'archived' => $mine()->whereNotNull('archived_at')->count()],
            'settingUp' => ! $this->checklist->for($request->user())['finished'],
            'formats' => array_map(fn (string $extension) => '.'.$extension, CommodityListReader::EXTENSIONS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);

        $this->write($owner, new PriceBookItem(['user_id' => $owner, 'sample_count' => 1]), $this->validated($request, $owner));

        return back()->with('success', 'Item added.');
    }

    public function update(Request $request, PriceBookItem $item): RedirectResponse
    {
        $owner = $this->owner($request);
        abort_unless($item->user_id === $owner, 404);

        $this->write($owner, $item, $this->validated($request, $owner, $item));

        return back()->with('success', 'Item updated.');
    }

    /** Archives an item (or, with `restore`, brings it back): kept, but nothing is priced from it. */
    public function archive(Request $request, PriceBookItem $item): RedirectResponse
    {
        $owner = $this->owner($request);
        abort_unless($item->user_id === $owner, 404);

        $item->forceFill(['archived_at' => $request->boolean('restore') ? null : now()])->save();

        return back()->with('success', $request->boolean('restore') ? 'Item restored.' : 'Item archived. It is kept, and can be restored.');
    }

    /** Reads each uploaded file, in whatever form it is, and adds what it understood to the list. */
    public function import(Request $request): RedirectResponse
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

        $back = back()->with('success', 'Read '.implode(', ', $read).". Added {$result['created']} new ".str('item')->plural($result['created']).($result['updated'] > 0 ? " and updated {$result['updated']}" : '').'. Check them over — you can edit or archive any item.');

        return $problems === [] ? $back : $back->with('warning', implode(' ', $problems));
    }

    public function template(Request $request): StreamedResponse
    {
        $this->owner($request);

        return response()->streamDownload(fn () => print ($this->reader->template()), 'commodity-list-template.csv', ['Content-Type' => 'text/csv']);
    }

    /** "Save Default List": the list is ready, so carry on with setup. */
    public function save(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);

        if (! PriceBookItem::query()->active()->where('user_id', $owner)->exists()) {
            return back()->with('warning', 'Add at least one item before saving the list — or skip this step and add items later.');
        }

        return redirect()->route('home')->with('success', 'Your default commodity list is saved. Projects without a rate list of their own are priced from it.');
    }

    /** "Skip and add later": leaves the step for later on the checklist. */
    public function skip(Request $request): RedirectResponse
    {
        $this->owner($request);
        $company = CompanyProfile::query()->findOrFail($request->user()->company_id);

        $company->update(['onboarding_skipped' => collect($company->onboarding_skipped ?? [])->push(SetupChecklist::COMMODITIES)->unique()->values()->all()]);

        return redirect()->route('home');
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
     * cannot overwrite it.
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
        // Clearing the code or markup on the form clears it on the item.
        $item->item_code = filled($data['item_code'] ?? null) ? $data['item_code'] : null;
        $item->markup_pct = $data['markup_pct'] ?? null;
        // Editing an archived item does not bring it back; restoring does.
        $item->archived_at = $archivedAt;
        $item->save();
    }

    /** Whose list this is (the company's book, kept under the account that set the company up) — and only for those who price work. */
    private function owner(Request $request): int
    {
        $user = $request->user();

        abort_unless(
            $user->company_id !== null
                && in_array(mb_strtolower(trim((string) $user->role)), config('estimates.reviewer_roles'), true),
            403,
        );

        return (int) Ownership::bookOwnerId($user->id);
    }
}
