
![cr_1Mrows](https://github.com/user-attachments/assets/3d762ef5-139d-45e4-ba6c-234cbb62b9d2)

# Import One Million Rows To The Database (Laravel/PHP)

This is the source code of the [video]() for the Laravel YouTube channel, where I try different approaches to import one million rows from a CSV file to a MySQL database.

This repo includes:

- Examples of CSV files from Git LFS
- A `CustomersImportCommand` console command that will automatically show benchmarks for the current command
- An `ImportHelper` trait that contains the code for benchmarking and several approaches I show in the video

Please be aware that this video and code is about having fun trying to import huge CSV files. Many real-world aspects, like validation, data quality, multiple tables, etc., are not included.

