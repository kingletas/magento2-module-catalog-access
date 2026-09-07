# Commerce_CatalogAccess

The handful of catalogue reads that every module ends up writing — load a product, turn category ids into names, find out which colour a variant is, do all of it in the right store — written once, with the mistakes they invite designed out.

None of these are hard. That's exactly why they are worth a module: each one is easy enough to write inline in four lines, and each of those four-line versions has the same defect as the last one. The repository throws where the caller expected null. The loop loads one product per iteration. The store-scoped query returns nothing because the store row doesn't exist. `trim()` gets a null. The emulation never gets stopped because the callback threw.

The tables below name the symptom each shortcut produces, not the style rule it breaks.

---

## What's inside

| Contract | Default | Use it for |
| --- | --- | --- |
| `ProductLocatorInterface` | `Model\Product\ProductLocator` | One product by SKU or id — null when absent, memoised per store |
| `ProductBatchLoaderInterface` | `Model\Product\ProductBatchLoader` | Many products in one query; `eachBySkus()` for catalogue-sized work |
| `AttributeValueReaderInterface` | `Model\Attribute\AttributeValueReader` | Reading a value off a loaded entity and getting the type you asked for |
| `AttributeOptionLabelResolverInterface` | `Model\Attribute\AttributeOptionLabelResolver` | Option ids to labels, dropdowns and source models alike |
| `CategoryNameResolverInterface` | `Model\Category\CategoryNameResolver` | Category names and full name paths, batched and store-scoped |
| `ProductCategoryIdsInterface` | `Model\Category\ProductCategoryIds` | Which categories products are in — assigned, or visible in a store |
| `ConfigurableVariantsInterface` | `Model\Configurable\ConfigurableVariants` | A configurable's children, and each child's colour/size axes |
| `StoreScopeInterface` | `Model\Store\StoreScope` | Running anything in another store, with the environment always restored |
| — | `Model\Db\StagedEntityFilter` | The link field and live-version window every catalogue query needs |
| — | `Model\Memo\RequestMemo` | The bounded LRU memo every resolver here is built on |

There's no configuration, no table, no ACL and no route. It's a library of contracts.

---

## The problems it's built to prevent

### Absence, types and shapes

| Written the usual way | What goes wrong | What this does instead |
| --- | --- | --- |
| `$productRepository->get($sku)` | Throws on a missing SKU. Two loaders here have no `try` at all, so one discontinued SKU fails a whole order export, and a mistyped gift SKU in a cart rule is a fatal in the cart | `findBySku()` returns null; `getBySku()` throws — the choice is at the call site and visible |
| `catch (\Exception) { continue; }` around it | A broken attribute backend or a DB error reads as "no such product". A broken catalogue looks like an empty one | Only `NoSuchEntityException` is caught. Everything else propagates |
| `trim($product->getData('x'))`, `strpos($url, …)` | PHP 8 turned "null where a string was expected" into a fatal, and third-party extensions have needed patching for it repeatedly (`strpos(): Passing null`, `trim(null)`) | `getString()` always returns a string, `getStringOrNull()` when the difference matters |
| `$row['value_index']`, `$payload['given_name']` | `Undefined array key` — patched three times in third-party code here, and once in Magento's own configurable save | `has()` answers whether the value is there before anything reads it |
| `(bool) $product->getData('flag')` | `(bool) '0'` is true. Its mirror, the string `'false'`, is also true | `getBool()` uses yes/no semantics, and an empty value takes your default rather than becoming false |
| `implode(',', $value)` on a select, or `(string)` on a multiselect | One works until someone changes the input type in the admin; the other puts the literal word `Array` in a feed column | `getList()` takes `"12,47"`, `[12, 47]` or `"12"` and always returns a list |

### Queries that grow with the loop

| Written the usual way | What goes wrong | What this does instead |
| --- | --- | --- |
| `foreach ($skus as $sku) { $repo->get($sku); }` | One full load per SKU, and the cost grows with the list | `loadBySkus()` — one query per chunk of 500 |
| `foreach ($categoryIds as $id) { $categoryFactory->create()->load($id); }` | One load per category, per product, per totals collection — and totals are collected repeatedly, so a single cart can run thousands | `getNames()` — one query for the whole set |
| `$item->getProduct()->getCategoryIds()` per quote item | Loads the whole product to reach the ids. Hand-rolled replacements tend to be worse: a `UNION ALL` across the assignment table and one index table per store view, cached with no tag and no lifetime, so a re-assignment never reaches the storefront | `getAssignedCategoryIds()` / `getVisibleCategoryIds()` — one query per batch, request-scoped memo, and the two different questions kept apart |
| `extractAttributeValueLabel()`-style scans of `$attribute->getOptions()` | A linear scan per child per attribute, so the cost is quadratic in a configurable's option count and almost none of it's database time | `getVariantLabels()` builds the option map once per axis — three queries and one label lookup per attribute, whatever the child count |
| `getTypeInstance()->getUsedProducts($product)` | Loads every child as a full product with every attribute, to read their SKUs | `getChildSkus()` — one query, SKUs only |
| `loadBySkus($fortyThousandSkus)` | One array of forty thousand loaded products is the same out-of-memory failure that loading them one at a time was a slow one — and it lands at the end of a long export | `eachBySkus()` hands over one chunk at a time and keeps none of them |

### Scope, store and edition

| Written the usual way | What goes wrong | What this does instead |
| --- | --- | --- |
| Joining category names at store scope | Returns nothing for every category with no store override, which is most of them. The change that makes a feed store-aware is the change that empties it | `COALESCE(store_value, default_value)`, the way Magento's own model resolves it |
| Joining catalogue tables on `entity_id` | On Adobe Commerce an entity is one row per scheduled update and its values hang off `row_id`. The query doesn't fail — it joins the wrong rows, and returns next month's data today | `StagedEntityFilter` supplies the link field and narrows every query to the version that's live now, and is a no-op on Open Source |
| `$product->getAttributeText('color')` | Labels come from a source model cached for whichever store the shared attribute instance was last set to; returns a string for a select and an array for a multiselect | `getLabels()` reads store-scoped labels without touching the shared attribute; `resolveValue()` always returns a list |
| Hand-rolled `eav_attribute_option_value` query | Returns nothing at all for status, yes/no and any custom source model — silently, so the column just ships empty | The source is inspected first, and each kind is asked the way it can answer |
| `startEmulation(); … ; stopEmulation();` | A throw skips the stop. Every later message in that consumer runs as the wrong store, and the symptom surfaces far from the cause | `run()` puts the stop in a `finally` |
| Nested emulation | Magento allows one level and silently returns from the second — the framework's own log line saying so is commented out. The inner block runs in the outer store | The scope keeps its own stack: the inner store is really entered, and leaving it restores the enclosing one |
| A product collection in the frontend area | `Magento\CatalogInventory\Model\AddStockStatusToCollection` is a `beforeLoad` plugin registered **only** there. The same code returns everything in cron and silently drops out-of-stock products on a storefront request | The loader sets `has_stock_status_filter`, so the answer doesn't depend on which area asked |
| Any per-request memo | Grows until a long-running consumer dies on the memory limit, usually half way through its output | Every memo is bounded and LRU |

---

## Using it

### One product, absence included

```php
$product = $this->productLocator->findBySku($sku);       // null if it is gone
$product = $this->productLocator->getBySku($sku);        // NoSuchEntityException if it is gone
$product = $this->productLocator->findById($id, $storeId);
```

After saving a product, drop it from the memo — otherwise the value you just wrote isn't the one you read back:

```php
$this->productRepository->save($product);
$this->productLocator->forget($product->getSku());
```

### Many products, one query

```php
$products = $this->productBatchLoader->loadBySkus($skus);           // keyed by the SKU you asked for
$lean     = $this->productBatchLoader->loadBySkus($skus, null, ['name', 'price', 'image']);
$parents  = $this->productBatchLoader->loadParentsBySkus($childSkus);
```

For anything catalogue-sized, stream it instead — same queries, one chunk alive at a time:

```php
$written = $this->productBatchLoader->eachBySkus($skus, function (array $products): void {
    foreach ($products as $sku => $product) {
        $this->writeFeedRow($sku, $product);
    }
});
```

These come from a collection, so they are cheap and complete enough to read, export, price or index — but they are **not** repository entities. No extension attributes, no options. Read from them; don't save them and don't add them to a quote. For that, `ProductLocatorInterface` returns the real thing. The split is in the method names because the alternative — one method that quietly returns either kind — is how a feed job writes a half-populated product back to the database.

### Reading a value without a fatal

```php
$name   = $this->values->getString($product, 'name');              // never null
$price  = $this->values->getFloat($product, 'special_price', 0.0);
$active = $this->values->getBool($product, 'is_featured');         // '0' is false, 'false' is false
$fits   = $this->values->getList($product, 'fit');                 // "12,47" or [12, 47] → ['12', '47']
$labels = $this->values->getLabels($product, 'color', $storeId);   // ['Ceil Blue']

if (!$this->values->has($product, 'expect_date')) {
    // Not "empty" — not loaded. Check before writing a blank into a feed.
}
```

It takes a `DataObject`, so the same reader works on categories, quote items and order items.

### Categories, ids and names

```php
$idsByProduct = $this->productCategories->getAssignedCategoryIds($productIds);
$visible      = $this->productCategories->getVisibleCategoryIds($productIds, $storeId);  // anchors included

$names = $this->categoryNames->getNames(array_merge(...array_values($idsByProduct)));
$path  = $this->categoryNames->getPath($categoryId);                  // "Women > Tops > Blouses"
$paths = $this->categoryNames->getPaths($categoryIds, $storeId, ' | ');
```

Assigned and visible are different questions, and on an anchored catalogue they give different answers — which is why they are different methods. Roots are excluded from paths by level, not by name, so a store whose root category is called "Women" keeps its "Women" category.

### Configurable variants

```php
$children = $this->variants->getChildSkus(['SCRUB-TOP']);
// ['SCRUB-TOP' => ['SCRUB-TOP-CEIL-S', 'SCRUB-TOP-CEIL-M', …]]

$axes = $this->variants->getVariantLabels($childSkus, $storeId);
// ['SCRUB-TOP-CEIL-S' => ['color' => 'Ceil Blue', 'size' => 'Small'], …]
```

Three queries and one label lookup per axis, however many children are in the batch. Option ids need no store — Magento requires a configurable's attributes to be global scope — but labels do.

### Option labels

```php
$label  = $this->optionLabels->getLabel('color', $product->getData('color'));
$labels = $this->optionLabels->getLabels('color', $optionIds, $storeId);
$fits   = $this->optionLabels->resolveValue('fit', $product->getData('fit'));  // handles "12,47"
```

For another entity's attributes, give the resolver a different entity type — it's a `di.xml` argument, not a constant:

```xml
<virtualType name="Acme\Feed\Model\CategoryOptionLabels"
             type="Commerce\CatalogAccess\Model\Attribute\AttributeOptionLabelResolver">
    <arguments>
        <argument name="entityTypeCode" xsi:type="string">catalog_category</argument>
    </arguments>
</virtualType>
```

### Another store's environment

```php
$html = $this->storeScope->run($order->getStoreId(), fn (int $storeId) => $this->renderEmail($order));

$feeds = $this->storeScope->runForEach($storeIds, fn (int $storeId) => $this->buildFeed($storeId));
```

Exceptions propagate — the environment is restored first.

---

## Gotchas

- **The memos are wired in `di.xml`, and that wiring isn't tuning.** The container shares one instance per type, so injecting `RequestMemo` directly would give every resolver the same memo: one key namespace, one size bound, and a product-heavy loop evicting the category names it's about to ask for again. Each consumer has its own `virtualType`.
- **`forget()` after every product save.** Nothing here observes `catalog_product_save_after`, deliberately — a memo that invalidates itself from an event is a memo that behaves differently depending on which code path saved the product. Call it where you save.
- **Nothing is written to the persistent cache.** One batched query per request per store already removes the cost that matters, and a cached category name is a name that has to be invalidated on rename, on move, on store-view change and on scheduled-update go-live. Four contracts to get right in exchange for microseconds. If your store wants one, replace the `<preference>` — that's what the interface is for. The version that goes wrong quietly is a cache with no tag and no lifetime: a category renamed in the admin never reaches the storefront until somebody flushes it by hand.
- **`loadBySkus()` doesn't filter by website.** `addStoreFilter()` would turn "load these products" into "load these products, if this store happens to sell them", and the call site can't tell that result from a deleted SKU.
- **Option ids include zero.** A yes/no attribute's "No" is option `0`, and treating it as empty is why those columns come out of an export blank.
- **`getString()` refuses arrays rather than joining them.** Joining would be a guess at a separator; `getList()` is where you say what you meant.
- **`StoreScope::getCurrentStoreId()` answers `0` when there's no current store.** In a CLI process that's the honest answer, and it's what `getStore()` would have thrown about.

---

## Tests

```bash
make check
```

The coding standard and all four suites — 194 tests, no database and no Magento bootstrap. Narrow it to one suite with `SUITE`:

```bash
make test SUITE=behaviour
```

Every query is asserted against the `Select` it was built on — the store fallback, the version window, the link field on each side of a join, the chunking, the default-scope read — so the SQL is covered without an installation to run it against.

---

## Rebranding

```bash
php ../bin/rebrand Acme
```

There are no config paths or table names to migrate afterwards: this module owns neither.
