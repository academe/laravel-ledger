# Referencing models

[← Back to README](../README.md)

A `JournalTransaction` can optionally reference any other model — a
product, an invoice, an order — via its own `reference` polymorphic morph:

```php
$transaction = $journal->credit(Money::USD(999), 'Sale');
$transaction->reference()->associate($product)->save();
```

To read transactions back from the referenced model's side, add the
`HasJournalTransactions` trait:

```php
use Academe\LaravelJournal\Concerns\HasJournalTransactions;

class Product extends Model
{
    use HasJournalTransactions;
}

$product->journalTransactions; // Collection<JournalTransaction>
```
