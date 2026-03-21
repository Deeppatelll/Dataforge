# 🚀 DataForge – Real-Time Data Analytics Pipeline

DataForge is a distributed, real-time data analytics platform that enables ingestion, processing, indexing, and visualization of structured data using an event-driven architecture.

It leverages Apache Kafka for streaming, Apache Solr for indexing/search, and a React-based UI for analytics visualization — all orchestrated via Docker.


## ✨ Key Features



## 🏗️ Architecture

```text
CSV → Kafka Producer → Kafka Topic → Worker Consumer → Solr Index → React UI

📦 Components

backend/api → Handles CSV ingestion & API endpoints

backend/worker → Consumes Kafka messages & pushes to Solr

frontend → Dashboard UI for querying & visualization

Kafka → Event streaming backbone

Solr → Search & indexing engine

⚙️ Tech Stack

| Layer     | Technology                 |
| --------- | -------------------------- |
| Frontend  | React (Vite), Tailwind CSS |
| Backend   | PHP 8.2                    |
| Streaming | Apache Kafka               |
| Search    | Apache Solr                |
| DevOps    | Docker, Docker Compose     |

🚀 Getting Started (Automated Pipeline Setup)

Step 1: Start All Services
cd C:\xampp\htdocs\kafka-php\DataForge
docker compose up

This will automatically start:

## 🏗️ Architecture
Kafka broker

flowchart LR
      CSV[CSV File] --> Producer[Kafka Producer (API)]
      Producer --> Topic[Kafka Topic]
      Topic --> Worker[Worker Consumer]
      Worker --> Solr[Apache Solr Index]
      Solr --> UI[React Dashboard (UI)]

Solr
Backend API
Worker (consumer)
Frontend UI

Step 2: Ingest Data (CSV → Kafka → Solr)
docker compose exec api php backend/api/producer.php data\yourfile.csv

Flow:

CSV file is read

Data is sent to Kafka topic

Worker consumes messages

Data is indexed into Solr

UI becomes query-ready

Step 3: Monitor Processing (Optional)
docker compose logs -f worker
Step 4: Access UI

http://localhost:3000

🔁 End-to-End Data Flow
CSV File
   ↓
Kafka Producer (API)
   ↓
Kafka Topic
   ↓
Worker Consumer
   ↓
Apache Solr Index
   ↓
React Dashboard (UI)

📌 Use Cases

Real-time data analytics dashboards

CSV ingestion & indexing systems

Event-driven data pipelines

Search-based reporting tools

📁 Project Structure
frontend/   # React (Vite) UI (main code)
backend/
  ├── api/
  └── worker/
docs/
sample-data/
scripts/
docker-compose.yml

⚠️ Notes

Ensure Docker is running before starting services

First run may take time (image builds)

Use proper CSV format for ingestion

👨‍💻 Author

Deep Patel
Full Stack Developer | MERN | Kafka | System Design

⭐ Support

If you found this project useful, consider giving it a star!
//line 1
