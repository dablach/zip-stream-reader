# Stream Reader for ZIP files

Read ZIP files from stream in a memory efficient way without first downloading the whole file.

> [!NOTE]
> ZIP files can contain entries with no size set in the local file header. These entries can only be read after parsing the central directory which is at the end of the file, making it impossible to read such a file in a streaming fashion. When `ZipStreamReader` encounters such an entry in an unseekable stream, it wil load the remaining contents into `php://temp` and read the rest from there.

## Usage

```php
$reader = ZipStreamReader::open("https://...");

foreach ($reader as $entry) {
  echo "Name: " . $entry->getName() . "\n";
  echo "Modified: " . $entry->getMtime()->format('r') . "\n";

  $handle = $entry->getStream();
  // $handle is a resource you can use to read the file content (e.g. fread, fgetcsv ..)
}
```
