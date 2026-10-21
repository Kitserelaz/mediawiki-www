"use strict";

if (require.main === module) require("dotenv").config();
const express = require("express");
const compression = require("compression");
const { z } = require("zod");

const { createCounterStore } = require("./utils/counter-store");
const { themeList, getCountImage } = require("./utils/themify");
const { cors, ZodValid, asyncHandler } = require("./utils/middleware");
const { randomArray, logger } = require("./utils");

function createApp({ db, intervalMs = 0 }) {
  const counters = createCounterStore(db, { intervalMs, onError: (error) => logger.error("Counter flush failed", error) });
  const app = express();
  const nameSchema = z.object({ name: z.string().min(1).max(32) });

  app.use(express.static("assets"));
  app.use(compression());
  app.use(cors());
  app.set("view engine", "pug");

  app.get('/', (req, res) => {
    const site = process.env.APP_SITE || `${req.protocol}://${req.get('host')}`
    const ga_id = process.env.GA_ID || null
    res.render('index', {
      site,
      ga_id,
      themeList,
    })
  });

  // get the image
  app.get(["/@:name", "/get/@:name"],
    ZodValid({
      params: nameSchema,
      query: z.object({
        theme: z.string().default("moebooru"),
        padding: z.coerce.number().int().min(0).max(16).default(7),
        offset: z.coerce.number().min(-500).max(500).default(0),
        align: z.enum(["top", "center", "bottom"]).default("top"),
        scale: z.coerce.number().min(0.1).max(2).default(1),
        pixelated: z.enum(["0", "1"]).default("1"),
        darkmode: z.enum(["0", "1", "auto"]).default("auto"),

        // Unusual Options
        num: z.coerce.number().int().min(0).max(1e15).default(0), // a carry-safe integer, less than `2^53-1`, and aesthetically pleasing in decimal.
        prefix: z.coerce.number().int().min(-1).max(999999).default(-1)
      })
    }),
    asyncHandler(async (req, res) => {
      const { name } = req.params;
      let { theme = "moebooru", num = 0, ...rest } = req.query;

      // This helps with GitHub's image cache
      res.set({
        "content-type": "image/svg+xml",
        "cache-control": "max-age=0, no-cache, no-store, must-revalidate",
      });

      const data = name === "demo" ? { name, num: "0123456789" }
        : num > 0 ? { name, num } : await counters.increment(name);

      if (name === "demo") {
        res.set("cache-control", "max-age=31536000");
      }

      if (theme === "random") {
        theme = randomArray(Object.keys(themeList));
      }

      // Send the generated SVG as the result
      const renderSvg = getCountImage({
        count: data.num,
        theme,
        ...rest
      });

      res.send(renderSvg);

      logger.debug(
        data,
        { theme, ...req.query },
        `ip: ${req.headers['x-forwarded-for'] || req.connection.remoteAddress}`,
        `ref: ${req.get("Referrer") || null}`,
        `ua: ${req.get("User-Agent") || null}`
      );
    })
  );

  // Reading a record must not add a visit.
  app.get("/record/@:name", ZodValid({ params: nameSchema }), asyncHandler(async (req, res) => {
    res.set("cache-control", "no-store");
    res.json(await counters.read(req.params.name));
  }));

  app.get("/heart-beat", (req, res) => {
    res.set("cache-control", "max-age=0, no-cache, no-store, must-revalidate");
    res.send("alive");
    logger.debug("heart-beat");
  });

  app.use((error, req, res, next) => {
    logger.error("Request failed", error);
    if (res.headersSent) return next(error);
    res.status(500).type("application/json").json({ code: 500, message: "Unable to process counter request." });
  });

  return { app, counters };
}

function start() {
  require("dotenv").config();
  const db = require("./db");
  const interval = Number(process.env.DB_INTERVAL);
  const { app, counters } = createApp({
    db,
    intervalMs: Number.isFinite(interval) && interval > 0 ? interval * 1000 : 0,
  });
  const listener = app.listen(process.env.APP_PORT || 3000, () => {
    logger.info("Your app is listening on port " + listener.address().port);
  });
  let stopping = false;
  const shutdown = () => {
    if (stopping) return;
    stopping = true;
    listener.close(async () => {
      try {
        await counters.close();
      } catch (error) {
        logger.error("Unable to flush counters during shutdown", error);
        process.exitCode = 1;
      } finally {
        try {
          await db.close();
        } catch (error) {
          logger.error("Unable to close counter database", error);
          process.exitCode = 1;
        }
      }
    });
  };
  process.once("SIGTERM", shutdown);
  process.once("SIGINT", shutdown);
  return listener;
}

if (require.main === module) start();
module.exports = { createApp, start };
