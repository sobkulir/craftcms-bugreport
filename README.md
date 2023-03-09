# A demo for CraftCMS bug

Issue: https://github.com/craftcms/cms/issues/12847

Querying GlobalSet in a Custom Field's `init()` creates an endless recursion. The relevant code is
in `modules/Module.php`. To reproduce, run:

1. `chmod -R a+rw .`
2. `docker-compose up`
3. Open `http://localhost:8080/`

