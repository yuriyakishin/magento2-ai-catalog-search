# Magento 2 AI Catalog Search — Natural Language Product Search with Automatic Filters

<a href="https://yu.net.ua/" target="_blank" rel="noopener noreferrer"><img alt="Live demo" src="https://img.shields.io/badge/demo-yu.net.ua-2ea44f?style=for-the-badge"></a>

**🔗 Demo: <a href="https://yu.net.ua/" target="_blank" rel="noopener noreferrer">yu.net.ua</a>**

Magento's native search matches literally on words: a customer who types
"red jacket for men under $50" gets results for that exact phrase —
Magento doesn't understand that "red" is a color, "for men" is a catalog
section, and "$50" is a price ceiling, unless the customer opens the
filters manually and sets them himself. `Yu_AiCatalogSearch` plugs AI
straight into the storefront search box: a plain-language query turns
itself into the right filters.

## What the customer sees

The customer just types a query as usual:

> "red jacket for men under $50" → the AI figures out on its own: color —
> red, category — men's, price — under $50, and applies it as ordinary
> search filters.

## Two modes — the merchant's choice

The module offers two ways to show the result — switched by a single
field in the admin panel:

- **Native (default)** — the customer lands on Magento's regular search
  results page, with the usual filter navigation on the left, just with
  the right filters already applied. Fully native storefront look, zero
  visual difference from the theme — the safest option to roll out.

  ![Native mode — the regular search results page with filters already applied](docs/images/search-results-native.png)

- **AI** — the customer lands on a dedicated page with refinement chips
  above the results: categories first ("Men › Tops · 10"), then
  attributes ("Material: Cotton · 7"), then a price ceiling ("Under $40
  · 5"), built from the products actually shown rather than a static
  filter tree. Each chip is a value that really splits the current
  results - not one nearly all of them share, not one only a single
  product has. The number on a chip is exactly how many products a
  click shows: a click narrows the products already on the page, the
  same way a layered-navigation filter does, instead of starting a new
  search. Chips stack (each click narrows further) and every active
  chip has a × that removes just that one. The product grid itself
  stays fully native: colors, swatches, wishlist, compare, sorting,
  pagination — all as usual.

  ![AI mode — a dedicated page with AI suggestions above the results](docs/images/search-results-AI.png)

If the AI is unavailable for any reason, or fails to parse the query,
search simply works as usual, unchanged — the customer won't even notice.

## Features

- **Understands attributes, category and price** in a single query, in
  whatever language the customer uses.
- **Semantic matching in AI mode** — when `Yu_AiSearchEngine`'s
  semantic search is enabled, product matching also considers meaning,
  not just literal words: a query like "warm winter jacket" can surface
  a product described only as "insulated parka". Off by default; see
  `Yu_AiSearchEngine`'s README for how to turn it on.
- **Never breaks plain text search** — if the query carries no filters,
  nothing changes compared to regular search.
- **Query-parse caching** — identical queries don't spend a repeat AI
  call.
- **Query log** — every parse is recorded (what the customer typed, what
  the AI recognized, the cost of the call).
- **Never touches catalog settings** — the module only reads which
  attributes the merchant has configured as filterable, and never
  changes those settings itself.

## Requirements

- PHP >= 8.1
- Magento 2.4.x with Elasticsearch/OpenSearch configured
- The `Yu_AiLlm` and `Yu_AiSearchEngine` modules (installed automatically
  as dependencies)
- An API key for at least one supported LLM provider

## Installation

```bash
composer require yu-dev/module-ai-catalog-search
bin/magento module:enable Yu_AiLlm Yu_AiSearchEngine Yu_AiCatalogSearch
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

The module is installed disabled — search behavior won't change until
you enable it in the admin panel and configure at least one LLM
provider.

## Configuration

**Stores → Configuration → Search Product AI → General**

- **Enable AI Search** — the main switch.
- **Results Mode** — Native or AI, see above.
- **Minimum Query Length** — queries shorter than this aren't sent to
  the AI (e.g. single letters or product codes).
- **Parse Timeout** — how long to wait for the AI's answer before
  falling back to unmodified regular search.
- **Parse Cache Lifetime** — how long to remember the parse of identical
  queries.
- **Log Search Queries** — keep a query log.

For Native mode to work fully, the merchant's catalog needs Magento's
own "Use in Search Results Layered Navigation" setting enabled for the
relevant attributes and for price (Stores → Attributes → Product →
the attribute in question) — without it the AI still understands the
query just fine, but can't apply the filter as a separate parameter, and
instead just folds it into the keyword search. AI mode needs no such
setting.

A category the AI is expected to recognize in a query (e.g. "for men",
"women's") must be marked as **Anchor** (Catalog → Categories → the
category → Display Settings → Is Anchor). Without this flag the category
doesn't participate in search — the AI simply can't apply it as a
filter, even when it correctly reads the customer's intent.

The LLM provider (OpenAI, Anthropic Claude, Google Gemini, xAI Grok and
others) is configured once, in **Stores → Configuration → AI → LLM
AI** — this section is shared across all AI modules on the store,
including `Yu_AiChat`.

## Author

Yuriy Akishin:
- 📧 Email: yuriy.akishin@gmail.com
- 💼 LinkedIn: https://www.linkedin.com/in/yuriyakishin/
- 💻 GitHub: https://github.com/yuriyakishin

## License

[MIT](LICENSE)
