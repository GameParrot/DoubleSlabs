# DoubleSlabs
Pocketmine plugin that allows for a different top half and bottom half of a slab (BETA)

# Usage
Place a slab while sneaking where another slab is. The slabs will combine to form a double slab of 2 different slab types.
![Screenshot 2024-11-05 20:48:11](https://github.com/user-attachments/assets/47058f29-07cd-4bf4-82f5-fe84827cc331)

# Limitations
This plugin uses block layers which are meant for waterlogging to store multiple blocks in a single position. Because of this, the second slab will not have a collision box and will not be selectable. Destroying the main slab will also destroy the other slab in the same block if present.
