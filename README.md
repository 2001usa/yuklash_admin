
# Telegram Admin Bot (Movie Uploader)

Ushbu bot Python tilida, `aiogram` kutubxonasi yordamida yaratilgan bo'lib, adminlarga kinolarni yoki animelarni yashirin Telegram kanaliga avtomatik tarzda joylash va ularning ma'lumotlarini PostgreSQL (Supabase) bazasiga saqlash imkonini beradi.

## Xususiyatlari

*   **Admin uchun cheklangan kirish**: Bot faqat belgilangan admin ID bilan ishlaydi.
*   **FSM (Finite State Machine)**: Ma'lumotlarni bosqichma-bosqich yig'ish uchun holat mashinasi ishlatiladi.
*   **Avtomatik qism raqamlash**: Yuklangan videolarga avtomatik tarzda "1-qism", "2-qism" kabi raqamlar beriladi.
*   **Chiroyli caption**: Kino ma'lumotlari va qism raqami bilan birga chiroyli matn (caption) yaratiladi.
*   **Supabase integratsiyasi**: Yuklangan kinolar va ularning qismlari haqidagi ma'lumotlar PostgreSQL bazasiga (Supabase orqali) saqlanadi.
*   **`message_id` saqlash**: Har bir yuborilgan videoning `message_id` va `channel_id` bazaga yoziladi, bu kelajakda tahrirlash yoki o'chirish uchun foydali bo'lishi mumkin.

## O'rnatish va Sozlash

### 1. Loyihani klonlash

```bash
git clone <repository_url>
cd telegram_admin_bot
```

### 2. Virtual muhit yaratish (tavsiya etiladi)

```bash
python3.11 -m venv venv
source venv/bin/activate
```

### 3. Kerakli kutubxonalarni o'rnatish

```bash
sudo pip3 install aiogram supabase python-dotenv
```

### 4. `.env` faylini sozlash

`.env.example` faylini `telegram_admin_bot/.env` nomiga o'zgartiring va quyidagi ma'lumotlarni to'ldiring:

```
BOT_TOKEN="YOUR_TELEGRAM_BOT_TOKEN"
ADMIN_ID="YOUR_TELEGRAM_ADMIN_ID"
SUPABASE_URL="YOUR_SUPABASE_PROJECT_URL"
SUPABASE_KEY="YOUR_SUPABASE_ANON_KEY"
CHANNEL_ID="YOUR_PRIVATE_CHANNEL_ID"
```

*   `BOT_TOKEN`: BotFather'dan olingan Telegram bot tokeni.
*   `ADMIN_ID`: Botni boshqaradigan adminning Telegram ID'si (raqamli).
*   `SUPABASE_URL`: Supabase loyihangizning URL manzili.
*   `SUPABASE_KEY`: Supabase loyihangizning `anon` kaliti.
*   `CHANNEL_ID`: Kinolar joylanadigan yashirin kanalning ID'si. Kanal ID'sini olish uchun botni kanalga admin qilib qo'shing va biror xabar yuboring, so'ngra `https://api.telegram.org/bot<BOT_TOKEN>/getUpdates` orqali tekshiring. ID manfiy son bo'ladi (masalan, `-1001234567890`).

### 5. Supabase (PostgreSQL) bazasini sozlash

`schema.sql` faylidagi SQL kodini Supabase loyihangizning SQL Editoriga joylashtiring va ishga tushiring. Bu `movies` va `movie_parts` jadvallarini yaratadi.

```sql
CREATE TABLE IF NOT EXISTS movies (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    studio VARCHAR(255),
    language VARCHAR(50),
    country VARCHAR(50),
    channel_link VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS movie_parts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    movie_id UUID REFERENCES movies(id) ON DELETE CASCADE,
    part_number INTEGER NOT NULL,
    message_id BIGINT NOT NULL,
    channel_id BIGINT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);
```

**Eslatma**: `anon` kalitiga `movies` va `movie_parts` jadvallariga `insert` huquqini berishingiz kerak bo'lishi mumkin. Supabase'da `Policies` bo'limiga o'ting va kerakli ruxsatlarni sozlang.

## Botni ishga tushirish

Barcha sozlashlar tugagandan so'ng, botni ishga tushirish uchun quyidagi buyruqni bajaring:

```bash
python3.11 main.py
```

## Botdan foydalanish

1.  Botni ishga tushirgandan so'ng, admin sifatida botga `/start` buyrug'ini yuboring.
2.  Bot sizdan kino ma'lumotlarini (nomi, studiya, til, davlat, kanal linki) ketma-ket so'raydi.
3.  Barcha ma'lumotlar kiritilgandan so'ng, bot sizdan videolarni yuborishni kutadi.
4.  Har bir videoni (qismni) botga yuboring. Bot uni avtomatik ravishda raqamlaydi, caption qo'shadi va belgilangan kanalga yuboradi.
5.  Barcha qismlar yuklab bo'lingach, `/done` buyrug'ini yuboring. Bot holatini tiklaydi va yangi kino qo'shishga tayyor bo'ladi.

## Muallif

**Manus AI**
