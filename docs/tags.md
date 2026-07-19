# Tags

[← Back to README](../README.md)

Each transaction has a `tags` attribute: a flat map of string keys to
scalar values, for labelling entries (`['source' => 'import',
'batch' => 42]`). The shape is deliberately opinionated — tags are labels,
not a document store — so lists, nested arrays, and objects are rejected
with `InvalidTags` on assignment. Reading always gives you an array (an
empty tag set is stored as `NULL` and reads as `[]`):

```php
$transaction->tags = ['status' => 'paid', 'attempts' => 2];
$transaction->save();

$transaction->fresh()->tags; // ['status' => 'paid', 'attempts' => 2]
```

The package never queries tags itself; if you filter on them, add a
driver-appropriate JSON index in your application (the column is `jsonb`
on Postgres).
