# Academy LMS

Continuing Medical Education platform (PHP 8.4 / MySQL).

## Mode A Product Owner demo

Human-visible demonstration of the real application (admissions → review → payment → Enrolment):

**Start here:** [`docs/demo/README.md`](docs/demo/README.md)

```bash
cp .env.example .env   # configure DB + PAYMENTS_FAKE_GATEWAY=1 + local document/email adapters
composer install && composer assets:install
php bin/jobs.php demo:prepare --confirm --migrate
composer demo-serve
# after uploads / demo payment:
php bin/jobs.php demo:process
```

`composer demo-serve` starts PHP with `upload_max_filesize=10M` and `post_max_size=16M` (required for 10 MB document uploads).
Login: `http://127.0.0.1:8080/login`  
Personas and walkthrough: [`docs/demo/DEMO_SCRIPT.md`](docs/demo/DEMO_SCRIPT.md)

## Documentation map

See [`docs/README.md`](docs/README.md). Agent rules: [`AGENTS.md`](AGENTS.md).
