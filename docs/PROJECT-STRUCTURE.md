# Project Structure

```text
CADARS-Members-System/
├── public/
│   ├── index.php
│   └── .htaccess
├── database/
│   └── .gitkeep
├── storage/
│   ├── .gitkeep
│   └── private/
├── docs/
├── scripts/
├── README.md
├── LICENSE
└── .gitignore
```

## `public/`

The only folder that should be exposed to the web.

## `database/`

Live SQLite database is created here.

Do not commit the live database.

## `storage/`

Live config, install lock, deployed version marker and private uploads.

Do not commit live runtime files.
