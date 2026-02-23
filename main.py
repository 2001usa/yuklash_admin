
import os
import asyncio
import logging
from dotenv import load_dotenv
from aiogram import Bot, Dispatcher, types, F
from aiogram.filters import Command, StateFilter
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.fsm.storage.memory import MemoryStorage
from supabase import create_client, Client

# .env faylini yuklash
load_dotenv()

# Konfiguratsiya
BOT_TOKEN = os.getenv("BOT_TOKEN")
ADMIN_ID = int(os.getenv("ADMIN_ID"))
SUPABASE_URL = os.getenv("SUPABASE_URL")
SUPABASE_KEY = os.getenv("SUPABASE_KEY")
CHANNEL_ID = int(os.getenv("CHANNEL_ID"))

# Supabase mijozini sozlash
supabase: Client = create_client(SUPABASE_URL, SUPABASE_KEY)

# Bot va Dispatcher
bot = Bot(token=BOT_TOKEN)
dp = Dispatcher(storage=MemoryStorage())

# Logging
logging.basicConfig(level=logging.INFO)

# FSM Holatlari
class MovieStates(StatesGroup):
    waiting_for_name = State()
    waiting_for_studio = State()
    waiting_for_language = State()
    waiting_for_country = State()
    waiting_for_channel_link = State()
    uploading_videos = State()

# Admin filtri
def is_admin(message: types.Message):
    return message.from_user.id == ADMIN_ID

# Start komandasi
@dp.message(Command("start"), F.from_user.id == ADMIN_ID)
async def cmd_start(message: types.Message, state: FSMContext):
    await state.clear()
    await message.answer("Salom Admin! Yangi kino qo'shishni boshlaymizmi?\nKino yoki Anime nomini kiriting:")
    await state.set_state(MovieStates.waiting_for_name)

# Kino nomi
@dp.message(MovieStates.waiting_for_name, F.from_user.id == ADMIN_ID)
async def process_name(message: types.Message, state: FSMContext):
    await state.update_data(name=message.text)
    await message.answer("Studiyani kiriting:")
    await state.set_state(MovieStates.waiting_for_studio)

# Studiya
@dp.message(MovieStates.waiting_for_studio, F.from_user.id == ADMIN_ID)
async def process_studio(message: types.Message, state: FSMContext):
    await state.update_data(studio=message.text)
    await message.answer("Tilini kiriting:")
    await state.set_state(MovieStates.waiting_for_language)

# Tili
@dp.message(MovieStates.waiting_for_language, F.from_user.id == ADMIN_ID)
async def process_language(message: types.Message, state: FSMContext):
    await state.update_data(language=message.text)
    await message.answer("Davlatni kiriting (yoki 'yo'q' deb yozing):")
    await state.set_state(MovieStates.waiting_for_country)

# Davlat
@dp.message(MovieStates.waiting_for_country, F.from_user.id == ADMIN_ID)
async def process_country(message: types.Message, state: FSMContext):
    await state.update_data(country=message.text)
    await message.answer("Kanal linkini kiriting:")
    await state.set_state(MovieStates.waiting_for_channel_link)

# Kanal linki va Bazaga kinoni saqlash
@dp.message(MovieStates.waiting_for_channel_link, F.from_user.id == ADMIN_ID)
async def process_channel_link(message: types.Message, state: FSMContext):
    data = await state.get_data()
    name = data['name']
    studio = data['studio']
    language = data['language']
    country = message.text # country o'rniga link kelyapti deb hisoblaymiz yoki avvalgisini olamiz
    channel_link = message.text
    
    # Kinoni bazaga saqlash
    movie_data = {
        "name": name,
        "studio": studio,
        "language": language,
        "country": data['country'],
        "channel_link": channel_link
    }
    
    response = supabase.table("movies").insert(movie_data).execute()
    movie_id = response.data[0]['id']
    
    await state.update_data(movie_id=movie_id, part_count=0, channel_link=channel_link)
    await message.answer(f"Kino bazaga saqlandi! Endi videolarni (qismlarni) ketma-ket yuboring.\n"
                         f"Tugatish uchun /done komandasini yuboring.")
    await state.set_state(MovieStates.uploading_videos)

# Videolarni qabul qilish va kanalga yuborish
@dp.message(MovieStates.uploading_videos, F.video, F.from_user.id == ADMIN_ID)
async def process_video(message: types.Message, state: FSMContext):
    data = await state.get_data()
    movie_id = data['movie_id']
    part_count = data.get('part_count', 0) + 1
    await state.update_data(part_count=part_count)
    
    caption = (
        f"🎬 {data['name']}\n"
        f"🏢 Studiya: {data['studio']}\n"
        f"🌐 Til: {data['language']}\n"
        f"🌍 Davlat: {data['country']}\n"
        f"🔢 Qism: {part_count}-qism\n\n"
        f"🔗 Kanal: {data['channel_link']}"
    )
    
    # Kanalga yuborish
    sent_msg = await bot.send_video(
        chat_id=CHANNEL_ID,
        video=message.video.file_id,
        caption=caption
    )
    
    # Bazaga qism ma'lumotlarini saqlash
    part_data = {
        "movie_id": movie_id,
        "part_number": part_count,
        "message_id": sent_msg.message_id,
        "channel_id": CHANNEL_ID
    }
    supabase.table("movie_parts").insert(part_data).execute()
    
    await message.answer(f"{part_count}-qism kanalga joylandi va bazaga saqlandi.")

# Tugatish
@dp.message(Command("done"), MovieStates.uploading_videos, F.from_user.id == ADMIN_ID)
async def cmd_done(message: types.Message, state: FSMContext):
    await state.clear()
    await message.answer("Barcha qismlar muvaffaqiyatli yuklandi! Yangi kino uchun /start bosing.")

# Kinolar ro'yxati
@dp.message(Command("list"), F.from_user.id == ADMIN_ID)
async def cmd_list(message: types.Message, state: FSMContext):
    await state.clear()
    response = supabase.table("movies").select("id, name, studio, language, country").execute()
    movies = response.data

    if not movies:
        await message.answer("📭 Hozircha hech qanday kino yo'q.")
        return

    text = "🎬 <b>Bazadagi kinolar:</b>\n\n"
    for i, movie in enumerate(movies, 1):
        # Qismlar sonini hisoblash
        parts_resp = supabase.table("movie_parts").select("id", count="exact").eq("movie_id", movie["id"]).execute()
        part_count = parts_resp.count or 0
        text += (
            f"{i}. <b>{movie['name']}</b>\n"
            f"   🏢 {movie['studio']} | 🌐 {movie['language']} | 🌍 {movie['country']}\n"
            f"   📹 Qismlar: {part_count} ta\n"
            f"   👉 /movie_{i}\n\n"
        )

    await message.answer(text, parse_mode="HTML")
    # Ro'yxatni state ga saqlaymiz
    await state.update_data(movie_list=[m['id'] for m in movies])

# Kino tafsilotlari
@dp.message(F.text.regexp(r"^/movie_(\d+)$"), F.from_user.id == ADMIN_ID)
async def cmd_movie_detail(message: types.Message, state: FSMContext):
    index = int(message.text.split("_")[1]) - 1
    data = await state.get_data()
    movie_list = data.get("movie_list", [])

    if not movie_list or index < 0 or index >= len(movie_list):
        await message.answer("❌ Avval /list buyrug'ini yuboring.")
        return

    movie_id = movie_list[index]
    movie_resp = supabase.table("movies").select("*").eq("id", movie_id).execute()
    movie = movie_resp.data[0]

    parts_resp = supabase.table("movie_parts").select("part_number, message_id").eq("movie_id", movie_id).order("part_number").execute()
    parts = parts_resp.data

    text = (
        f"🎬 <b>{movie['name']}</b>\n"
        f"🏢 Studiya: {movie['studio']}\n"
        f"🌐 Til: {movie['language']}\n"
        f"🌍 Davlat: {movie['country']}\n"
        f"🔗 Kanal: {movie['channel_link']}\n\n"
        f"📹 <b>Qismlar ({len(parts)} ta):</b>\n"
    )
    for part in parts:
        text += f"  {part['part_number']}-qism | message_id: {part['message_id']}\n"

    await message.answer(text, parse_mode="HTML")


async def main():
    await dp.start_polling(bot)

if __name__ == "__main__":
    asyncio.run(main())
