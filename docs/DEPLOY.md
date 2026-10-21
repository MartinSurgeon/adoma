# Putting Adoma online: Namecheap (Stellar) with cPanel Git Version Control

Adoma is copied from GitHub to the server by cPanel, which then runs the steps in `.cpanel.yml`. You build the
screens on your own computer and send them up with the code, because the server cannot build them itself.

**Rehearsed on 17 Oct 2026:** these exact steps were run on a clean copy, in production mode, on Apache. The owner
signed in with the temporary password, chose a new one, and saved a purchase (PCH-CEN-000001).

What you need: the cPanel login, access to the GitHub repository `MartinSurgeon/adoma`, and about an hour.
Below, `<user>` is your cPanel username (shown top right in cPanel) and `app.yourdomain.com` is the address the
client will use.

---

## Part A: Once, on your computer

**A1. Send your work to GitHub.** In the project folder (`C:\xampp\htdocs\adoma`), in PowerShell:

```
git push origin development-stage-1.0
```

**A2. Make the release.** This builds the screens and sends code + screens to the `production` branch:

```
.\scripts\release.ps1
```

You should see `Released <code>.` at the end. On GitHub, the branch list now has `production`.

---

## Part B: Once, in cPanel

**B1. PHP version.** cPanel → **Select PHP Version**:
- Current PHP version: **8.3** → **Set as current**.
- Under **Extensions**, make sure these are ticked: `bcmath`, `ctype`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`,
  `tokenizer`, `xml`, `zip`.

**B2. PHP, and Composer (which installs the PHP packages).** cPanel → **Terminal**, and type:

```
ls /opt/alt/php83/usr/bin/php
mkdir -p ~/bin && curl -sS -o ~/bin/composer.phar https://getcomposer.org/download/latest-stable/composer.phar
/opt/alt/php83/usr/bin/php ~/bin/composer.phar --version
```

The first line should print the path back. If it says "No such file", type `ls /opt/alt/`, note the PHP 8.x folder
you see (for example `php84`), and change every `php83` in `.cpanel.yml` to match, then repeat A2.
The last line should print `Composer version 2...`. (Namecheap does not provide Composer where cPanel usually
keeps it, so the deploy uses this copy in your home folder.)

**B3. Database.** cPanel → **MySQL Database Wizard**:
1. Database name: e.g. `adoma` (cPanel makes it `<user>_adoma`).
2. User: e.g. `adoma`, with a strong password (use **Password Generator**). Write it down.
3. Privileges: tick **ALL PRIVILEGES** → **Make Changes**.

Note the full names cPanel shows: `<user>_adoma` for both.

**B4. Subdomain.** cPanel → **Domains** (or **Subdomains**) → **Create A New Domain**:
- Domain: `app.yourdomain.com`
- Untick "Share document root"; Document root: **`/home/<user>/adoma/public`**

**B5. HTTPS.** cPanel → **SSL/TLS Status** → tick the new subdomain → **Run AutoSSL**. Wait until it shows a green
padlock (a few minutes).

**B6. Let cPanel read GitHub.** *Skip if the repository is public.*
1. cPanel → **SSH Access** → **Manage SSH Keys** → **Generate a New Key**. Key name `github`, **leave the password
   empty** (cPanel Git cannot use a key with a password) → **Generate Key**.
2. Back on Manage SSH Keys, beside `github.pub`: **Manage** → **Authorize**.
3. Beside `github.pub`: **View/Download** → copy the whole key (it starts `ssh-rsa` or `ssh-ed25519`).
4. GitHub → repository `adoma` → **Settings** → **Deploy keys** → **Add deploy key**. Title `Namecheap`, paste
   the key, leave **Allow write access** off → **Add key**.
5. cPanel → **Terminal**:
   ```
   printf "Host github.com\n  IdentityFile ~/.ssh/github\n" >> ~/.ssh/config
   chmod 600 ~/.ssh/config
   ssh -T git@github.com
   ```
   Type `yes` when asked. You should see *"Hi MartinSurgeon/adoma! You've successfully authenticated"*.
   If it hangs or says "Connection refused", Namecheap is blocking the connection: ask their support to allow
   outgoing SSH to github.com on port 22, or ask your developer to switch to an access-token address.

**B7. Clone.** cPanel → **Git Version Control** → **Create**:
- Clone a Repository: **on**
- Clone URL: `git@github.com:MartinSurgeon/adoma.git` (public repository: `https://github.com/MartinSurgeon/adoma.git`)
- Repository Path: `/home/<user>/repositories/adoma`
- Repository Name: `adoma` → **Create**.

Then **Manage** (beside it) → **Basic Information** → **Checked-Out Branch**: `production` → **Update**.

**B8. First deploy.** **Manage** → **Pull or Deploy** → **Deploy HEAD Commit**. It takes a few minutes the first
time (it downloads the PHP packages). When the "Last Deployment" time appears, the app's files are in
`/home/<user>/adoma`. It is not working yet: it has no settings.

**B9. The live settings.** cPanel → **Terminal**:

```
cd ~/adoma
cp .env.example .env
```

Then cPanel → **File Manager** → `adoma` → tick **Settings** (top right) → **Show Hidden Files** → right-click
`.env` → **Edit**. Change these lines (leave the rest):

```
APP_NAME=Adoma
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<user>_adoma
DB_USERNAME=<user>_adoma
DB_PASSWORD=the password from B3

SESSION_SECURE_COOKIE=true
```

Remove the `#` in front of any `DB_` line that has one. **Save Changes.**

`APP_DEBUG=false` matters: with it on, an error would show your passwords and code to whoever sees it.

Back in the **Terminal**:

```
/opt/alt/php83/usr/bin/php artisan key:generate --force
```

You should see *"Application key set successfully."*

**B10. Second deploy.** Git Version Control → **Manage** → **Pull or Deploy** → **Deploy HEAD Commit**. This time it
also creates the database tables.

**B11. The business and its owner.** In the **Terminal**:

```
cd ~/adoma
/opt/alt/php83/usr/bin/php artisan adoma:setup
```

It asks for the business name, each branch's name and code (the first is the head office), and the owner's name and
email. At the end it shows the owner's **temporary password, once**. Copy it somewhere safe and give it to the
owner. (It refuses to run a second time, so it can never make a second business.)

**B12. Try it.** Open `https://app.yourdomain.com`:
1. Sign in with the owner's email and the temporary password.
2. Adoma asks for a new password. Choose one.
3. You should land on Home, with the head office's name at the top.

The owner then adds everyone else under **Admin → Users**, and checks the branches under **Admin → Branches**.

**B13. Nightly backup of the database.** cPanel → **Cron Jobs** → Common Settings: **Once Per Day** → Command:

```
mkdir -p ~/backups && /usr/bin/mysqldump --defaults-extra-file=$HOME/.my.cnf <user>_adoma | gzip > ~/backups/adoma-$(date +\%F).sql.gz && find ~/backups -name 'adoma-*.sql.gz' -mtime +30 -delete
```

and create the file it reads, in the **Terminal** (put your real values in):

```
printf "[client]\nuser=<user>_adoma\npassword=the password from B3\n" > ~/.my.cnf
chmod 600 ~/.my.cnf
```

Next morning, File Manager → `backups` should hold `adoma-<date>.sql.gz`. Once a week, download the newest one to
another computer.

---

## Every update after that

1. On your computer: commit your work, then run `.\scripts\release.ps1`.
2. cPanel → **Git Version Control** → **Manage** → **Pull or Deploy** → **Update from Remote**, then
   **Deploy HEAD Commit**.

During the deploy, visitors see "being updated" for under a minute. The database is updated automatically, and the
server's `.env` and stored data are never touched.

---

## If something goes wrong

| What you see | What to do |
| --- | --- |
| "500 Server Error" or a blank page | In the Terminal: `tail -50 ~/adoma/storage/logs/laravel.log`, and send it to your developer. |
| A page with a list of folders, or "403 Forbidden" | The subdomain's document root is not `/home/<user>/adoma/public` (B4). |
| "No application encryption key" | B9's `key:generate --force` was missed. Run it, then deploy again. |
| "Access denied for user" | The `DB_` lines in `.env` do not match B3. Fix them, then in the Terminal run `cd ~/adoma && /opt/alt/php83/usr/bin/php artisan optimize`. |
| Deploy HEAD Commit is greyed out | The clone has uncommitted changes on the server, or is not on `production`. Check B7's branch. |
| The page shows "being updated" and stays | A deploy stopped halfway. In the Terminal: `cd ~/adoma && /opt/alt/php83/usr/bin/php artisan up`, then deploy again. |
| A black "404 Not Found" page from LiteSpeed (not Adoma's own page) | Either the document root is not `/adoma/public` (B4), or `~/adoma` is private to the account: `chmod 755 ~/adoma ~/adoma/public`. |
| Deploy says it is complete, but nothing changed | Read the newest log: `tail -30 ~/.cpanel/logs/$(ls -t ~/.cpanel/logs/ \| grep -i deploy \| head -1)`. |
| "The system cannot deploy" | `cd ~/repositories/adoma && git status --short` must print nothing. Delete any stray file it lists (usually `error_log`); if files show as deleted (`D`), run `git reset --hard HEAD`. Never change files in that folder by hand. |
| "Could not open input file: …composer…" | B2's `composer.phar` download was missed. Run it, then deploy again. |
| "No APP_KEY variable was found" | `.env` has no `APP_KEY=` line: `echo 'APP_KEY=' >> ~/adoma/.env`, then `key:generate --force` again. |
| "Specified key was too long; max key length is 1000 bytes" | The server's code is older than the InnoDB setting. Pull and deploy the latest release, run `php artisan config:clear`, then `migrate:fresh --force` (only on an empty or throwaway database). |
| "Table 'users' already exists" during a deploy | A first database build stopped halfway. On a new, empty install: `php artisan migrate:fresh --force`. Never on a database with real data: ask your developer. |

After changing `.env` at any time, run `cd ~/adoma && /opt/alt/php83/usr/bin/php artisan optimize` so the change
takes effect.

---

## Importing the client's purchases sheet (and removing it later)

`adoma:import-sheet` posts each row of the client's sheet (Date, Supplier, Weight_Both, Volume, Price) as a purchase at
the head office, on its own date, with Adoma's own figures. Running it twice adds nothing twice, and `--remove` takes
out exactly what it added.

While the import is in, those purchases count like any other: the suppliers are owed their amounts, and the gold is
in head office stock. A sale that takes "the oldest gold first" will take it from this imported gold, and once that
happens the import can no longer be removed (Adoma refuses, and names the sales to reverse first). **Remove it before
real trading starts at the head office.**

1. Deploy the latest release (above).
2. cPanel → **File Manager** → `adoma/storage/app` → create a folder **`imports`** → upload the `.xlsx` into it.
3. **Terminal**:
   ```
   cd ~/adoma
   /opt/alt/php83/usr/bin/php artisan adoma:import-sheet "storage/app/imports/2010 (current).xlsx"
   ```
   It says how many purchases came in, how many match the sheet's own amounts, which rows were over 100 kg, and
   which were left out (such as the sheet's totals row).
4. **To remove it later**:
   ```
   cd ~/adoma
   /opt/alt/php83/usr/bin/php artisan adoma:import-sheet --remove
   ```
   Type `yes` to confirm. The suppliers it created go too, if nothing else uses them, and purchase numbers carry on
   with no gap.
