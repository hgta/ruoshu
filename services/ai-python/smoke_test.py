"""端到端冒烟测试：plan → embed → extract 全链路。"""
import requests

TEXT = (
    "夜色渐深，他推开门，屋内无人。桌上放着一封信，拆开来看，字迹清秀。"
    "他抬头望向窗外，月光洒了一地，像碎银。那封信的内容，写着一个很久以前的名字。\n\n"
    "信中说：'前路未明，愿君珍重。若他日重逢，仍是此间少年心性。'"
    "她读完，把信折好，塞回信封，抬头望向远方。灯火阑珊处，有人在等。\n\n"
    "推开门的时候，寒风迎面。她裹紧衣衫，快步走向街角的茶馆。"
    "推门而入，只见那人已等候多时，桌上茶已凉，灯已暗。"
    "他抬头，微微点头：'你来了。'声音平静，却藏着十年重逢的波澜。\n\n"
    "他们相对而坐，茶续了一盏又一盏。窗外月色清冷，窗内灯火微暖。"
    "话语不多，却字字千钧。从少年意气，到江湖飘零，再到今日重逢。"
    "她问：'这些年，你都在哪里？'他答：'在心里。'"
)


USER_ID = 8848
CHAPTER_ID = 12345

USER_ID_2 = 42
CHAPTER_ID_2 = 999


def main() -> None:
    # 1. 规划
    plan = requests.post(
        "http://127.0.0.1:9001/v1/watermark/plan",
        json={"text": TEXT, "user_id": USER_ID, "chapter_id": CHAPTER_ID},
        timeout=5,
    ).json()
    print("PLAN payload_hex:", plan["payload_hex"])
    print("PLAN ternary:    ", plan["ternary"])
    print("PLAN anchors:    ", plan["anchor_positions"])
    print("PLAN zw_chars length:", len(plan["zw_chars"]))
    print()

    # 2. 嵌入
    embed = requests.post(
        "http://127.0.0.1:9001/v1/watermark/embed",
        json={"text": TEXT, "payload": plan["payload_hex"]},
        timeout=5,
    ).json()
    watermarked = embed["watermarked_text"]
    zw_count = sum(1 for c in watermarked if c in "\u200b\u200c\u200d")
    print("EMBED inserted_count:", embed["inserted_count"])
    print("EMBED zero-width chars in result:", zw_count)
    print()

    # 3. 提取（未篡改）
    extract = requests.post(
        "http://127.0.0.1:9001/v1/watermark/extract",
        json={"text": watermarked, "expected_chapter_id": CHAPTER_ID},
        timeout=5,
    ).json()
    print("EXTRACT (clean):")
    print("  found_zero_width:", extract["found_zero_width"])
    print("  payload_hex:     ", extract["payload_hex"])
    print("  user_id:         ", extract["user_id"])
    print("  chapter_id:      ", extract["chapter_id"])
    print("  version:         ", extract["version"])
    print("  ecc_valid:       ", extract["ecc_valid"])
    print("  confidence:      ", extract["confidence"])
    print()

    # 4. 篡改部分标点（删除 5 个"，"）
    tampered = watermarked
    for _ in range(5):
        tampered = tampered.replace("，", "", 1)
    extract2 = requests.post(
        "http://127.0.0.1:9001/v1/watermark/extract",
        json={"text": tampered, "expected_chapter_id": CHAPTER_ID},
        timeout=5,
    ).json()
    print("EXTRACT (after deleting 5 punct):")
    print("  found_zero_width:", extract2["found_zero_width"])
    print("  user_id:         ", extract2["user_id"])
    print("  confidence:      ", extract2["confidence"])
    print()

    # 5. 完全清洗零宽（盗版商清洗场景）
    cleaned = "".join(c for c in watermarked if c not in "\u200b\u200c\u200d")
    extract3 = requests.post(
        "http://127.0.0.1:9001/v1/watermark/extract",
        json={"text": cleaned, "expected_chapter_id": CHAPTER_ID},
        timeout=5,
    ).json()
    print("EXTRACT (zero-width stripped):")
    print("  found_zero_width:", extract3["found_zero_width"])
    print("  user_id:         ", extract3["user_id"])
    print("  confidence:      ", extract3["confidence"])
    print()

    # 6. per-user 唯一性：同章节 + 不同 user_id 应得到不同 payload
    plan4 = requests.post(
        "http://127.0.0.1:9001/v1/watermark/plan",
        json={"text": TEXT, "user_id": USER_ID_2, "chapter_id": CHAPTER_ID_2},
        timeout=5,
    ).json()
    print("PER-USER UNIQUENESS:")
    print("  user 8848 →", plan["payload_hex"])
    print("  user   42 →", plan4["payload_hex"])
    print("  payloads distinct:", plan["payload_hex"] != plan4["payload_hex"])


if __name__ == "__main__":
    main()