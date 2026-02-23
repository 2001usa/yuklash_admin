
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
